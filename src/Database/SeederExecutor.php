<?php

namespace AISeeder\Database;

use AISeeder\Analysis\ProjectAnalysis;
use AISeeder\Generation\FactorySource;
use AISeeder\Generation\GenerationStats;
use AISeeder\Generation\KeyAllocator;
use AISeeder\Generation\RelationshipResolver;
use AISeeder\Generation\RowStore;
use AISeeder\Generation\SeededRandom;
use AISeeder\Generation\TableGenerator;
use AISeeder\Generation\TemporalGenerator;
use AISeeder\Generation\UniqueTracker;
use AISeeder\Generation\ValueGenerator;
use AISeeder\Graph\DependencyOrder;
use AISeeder\Graph\Edge;
use AISeeder\Planning\GenerationPlan;
use AISeeder\Schema\TableSchema;
use AISeeder\Validation\GenerationException;
use AISeeder\Validation\GenerationValidator;
use Carbon\CarbonImmutable;
use Closure;
use Faker\Generator as Faker;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Database\Connection;
use Throwable;

/**
 * Executes a validated plan against the database: loads the rows generated data
 * must connect to, generates tables parent-first, inserts in chunks, back-fills
 * deferred cycle references, and verifies integrity before committing.
 */
final class SeederExecutor
{
    /** Bound parameters per statement, kept under the lowest common driver limit. */
    private const PARAMETER_BUDGET = ['sqlsrv' => 2000, 'sqlite' => 30000, 'default' => 60000];

    private ?Closure $progress = null;

    /**
     * @param  Closure(): string  $hashPassword
     */
    public function __construct(
        private readonly Connection $db,
        private readonly ProjectAnalysis $analysis,
        private readonly Faker $faker,
        private readonly Closure $hashPassword,
        private readonly ?Encrypter $encrypter = null,
        private readonly int $maxChunk = 500,
        private readonly int $existingRowsLimit = 100000,
        private readonly array $localeDefaults = ['country' => 'US', 'currency' => 'USD', 'app_locale' => 'en'],
        private readonly string $strategy = TableGenerator::STRATEGY_AI,
    ) {
    }

    /**
     * @param  Closure(string $table, int $done, int $total): void  $progress
     */
    public function onProgress(Closure $progress): self
    {
        $this->progress = $progress;

        return $this;
    }

    /**
     * @param  list<string>  $wipe  Tables to empty first (--fresh), already confirmed by the user.
     *
     * @throws GenerationException|Throwable
     */
    public function run(GenerationPlan $plan, DependencyOrder $order, array $wipe = []): GenerationStats
    {
        $started = microtime(true);
        $stats = new GenerationStats;

        // Automatic GC could run destructors in the middle of generating a value (Faker's
        // destructor reseeds mt_rand). Cycles are collected explicitly between chunks instead.
        $gcWasEnabled = gc_enabled();
        gc_disable();

        try {
            $this->db->transaction(function () use ($plan, $order, $wipe, $stats) {
                if ($wipe !== []) {
                    $this->wipe($order, $wipe);
                }

                $this->generate($plan, $order, $stats);
                $this->verify($plan, $order, $stats);
            });
        } finally {
            if ($gcWasEnabled) {
                gc_enable();
            }
        }

        $stats->seconds = microtime(true) - $started;

        return $stats;
    }

    private function generate(GenerationPlan $plan, DependencyOrder $order, GenerationStats $stats): void
    {
        $graph = $this->analysis->graph;
        $timeline = new TemporalGenerator($plan->start->getTimestamp(), $plan->end->getTimestamp());
        $relations = new RelationshipResolver($graph, $order);
        $keys = new KeyAllocator;
        $unique = new UniqueTracker;
        $serializer = new RowSerializer($this->db->getQueryGrammar()->getDateFormat(), $this->encrypter);
        $validator = new GenerationValidator;

        $values = new ValueGenerator(
            $this->faker, $timeline, $this->hashPassword,
            $this->localeDefaults['country'], $this->localeDefaults['currency'], $this->localeDefaults['app_locale'],
        );

        $generated = $plan->generatedTables();

        foreach ($this->tablesToLoad($order, $generated) as $table) {
            $relations->stores[$table] = $this->loadExisting($this->analysis->schema->table($table), $plan, in_array($table, $generated, true), $unique, $keys);
        }

        $generator = new TableGenerator($values, $timeline, $relations, $keys, $unique);

        foreach ($order->tables as $table) {
            $tablePlan = $plan->table($table);

            if ($tablePlan === null || $tablePlan->count === 0) {
                continue;
            }

            $schema = $this->analysis->schema->table($table);
            $model = $this->analysis->model($table);
            $random = SeededRandom::derive($plan->seed, $table);

            $chunkSize = $this->chunkSize(count($schema->columns));
            $done = 0;
            $factory = $this->factorySource($table);

            foreach ($generator->generate($schema, $tablePlan, $random, $chunkSize, $stats, $factory, $this->strategy) as $chunk) {
                $rows = array_map(fn (array $row) => $serializer->serialize($schema, $model, $row), $chunk);

                foreach ($rows as $row) {
                    $validator->validate($schema, $row);
                }

                $this->insert($schema, $rows, $tablePlan->field($schema->primaryKey[0] ?? '')?->option('strategy') === 'increment');

                $done += count($rows);
                $this->progress?->__invoke($table, $done, $tablePlan->count);

                gc_collect_cycles();
            }

            if ($factory?->unsafeReason() !== null) {
                $stats->notes[] = "{$factory->info->factory} was not used for [{$table}]: {$factory->unsafeReason()}";
            } elseif ($factory !== null) {
                $stats->factoriesUsed[$table] = $factory->info->factory;
            }

            if ($schema->singlePrimaryKey()?->autoIncrement && $this->db->getDriverName() === 'pgsql') {
                $this->resetSequence($schema);
            }
        }

        $this->backfill($plan, $order, $relations, SeededRandom::derive($plan->seed, 'backfill'), $stats);
    }

    private function factorySource(string $table): ?FactorySource
    {
        $info = $this->analysis->factory($table);

        if ($this->strategy === TableGenerator::STRATEGY_AI || $info === null) {
            return null;
        }

        $columns = array_keys(array_filter($this->analysis->schema->table($table)->columns, fn ($column) => ! $column->generated));

        return new FactorySource($this->db, $info, $table, $columns);
    }

    /**
     * Generated tables plus every table they reference, in dependency order.
     *
     * @param  list<string>  $generated
     * @return list<string>
     */
    private function tablesToLoad(DependencyOrder $order, array $generated): array
    {
        $needed = array_fill_keys($generated, true);

        foreach ($generated as $table) {
            foreach ($this->analysis->graph->parentEdges($table) as $edge) {
                $needed[$edge->parent] = true;
            }

            foreach ($this->analysis->graph->morphSlots($table) as $slot) {
                foreach ($slot->targets as $target) {
                    $needed[$target] = true;
                }
            }
        }

        foreach ($order->deferred as $edge) {
            if (isset($needed[$edge->child])) {
                $needed[$edge->parent] = true;
            }
        }

        return array_values(array_filter($order->tables, fn (string $table) => isset($needed[$table])));
    }

    private function loadExisting(TableSchema $schema, GenerationPlan $plan, bool $willGenerate, UniqueTracker $unique, KeyAllocator $keys): RowStore
    {
        $graph = $this->analysis->graph;
        $lookup = $schema->primaryKey;

        foreach ($graph->childEdges($schema->name) as $edge) {
            if (count($edge->parentColumns) === 1) {
                $lookup[] = $edge->parentColumns[0];
            }
        }

        $groups = [];

        foreach ($graph->parentEdges($schema->name) as $edge) {
            if (count($edge->columns) === 1) {
                $groups[] = $edge->columns[0];
            }
        }

        $lookup = array_values(array_unique($lookup));
        $groups = array_values(array_unique($groups));
        $store = new RowStore($schema->name, $lookup, $groups);

        $uniqueIndexes = $willGenerate ? UniqueTracker::indexes($schema, true) : [];
        $columns = array_values(array_unique([...$lookup, ...$groups, ...array_merge([], ...$uniqueIndexes)]));
        $hasCreatedAt = $schema->hasColumn('created_at');

        if ($columns === []) {
            return $store;
        }

        $query = $this->db->table($schema->name)
            ->select($hasCreatedAt ? [...$columns, 'created_at'] : $columns)
            ->orderBy($schema->primaryKey[0] ?? $columns[0])
            ->limit($this->existingRowsLimit);

        foreach ($query->cursor() as $row) {
            $row = (array) $row;
            $time = $hasCreatedAt && $row['created_at'] !== null
                ? CarbonImmutable::parse($row['created_at'])->getTimestamp()
                : $plan->start->getTimestamp();

            $store->add($row, $time);

            foreach ($uniqueIndexes as $index) {
                $unique->remember($schema->name, $index, $row);
            }
        }

        $key = $schema->singlePrimaryKey();

        if ($willGenerate && $key !== null && $key->family() === 'integer') {
            $keys->startAfter($schema->name, (int) $this->db->table($schema->name)->max($key->name));
        }

        return $store;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function insert(TableSchema $schema, array $rows, bool $explicitIdentity): void
    {
        $identityInsert = $explicitIdentity && $this->db->getDriverName() === 'sqlsrv' && $schema->singlePrimaryKey()?->autoIncrement;
        $table = $this->db->getQueryGrammar()->wrapTable($schema->name);

        if ($identityInsert) {
            $this->db->unprepared("SET IDENTITY_INSERT {$table} ON");
        }

        try {
            $this->db->table($schema->name)->insert($rows);
        } finally {
            if ($identityInsert) {
                $this->db->unprepared("SET IDENTITY_INSERT {$table} OFF");
            }
        }
    }

    /**
     * Fills references that were left null to break a dependency cycle. Where the parent
     * points back at the child (organizations.owner_id <-> users.organization_id), the
     * earliest related row is chosen, so an organization's owner is one of its members.
     */
    private function backfill(GenerationPlan $plan, DependencyOrder $order, RelationshipResolver $relations, SeededRandom $random, GenerationStats $stats): void
    {
        foreach ($order->deferred as $edge) {
            $generated = $stats->generated[$edge->child] ?? 0;

            if ($generated === 0 || ! isset($relations->stores[$edge->parent]) || $relations->stores[$edge->parent]->count === 0) {
                continue;
            }

            $child = $relations->stores[$edge->child];
            $parent = $relations->stores[$edge->parent];
            $childKey = $this->analysis->schema->table($edge->child)->primaryKey[0];
            $reverse = $this->reverseEdge($edge);
            $nullRate = (float) ($plan->table($edge->child)?->field($edge->columns[0])?->option('null_rate') ?? 0.0);
            $updates = [];

            for ($i = $child->count - $generated; $i < $child->count; $i++) {
                if ($random->chance($nullRate)) {
                    continue;
                }

                $candidates = $reverse === null ? [] : $parent->group($reverse->columns[0], $child->value($i, $reverse->parentColumns[0]));

                if ($candidates !== []) {
                    usort($candidates, fn (int $a, int $b) => $parent->time($a) <=> $parent->time($b));
                    $index = $candidates[0];
                } else {
                    $index = $random->skewedIndex($parent->count);
                }

                $updates[(string) $parent->value($index, $edge->parentColumns[0])][] = $child->value($i, $childKey);
            }

            foreach ($updates as $value => $keys) {
                foreach (array_chunk($keys, 500) as $batch) {
                    $this->db->table($edge->child)->whereIn($childKey, $batch)->update([$edge->columns[0] => $value]);
                }

                $stats->backfilled += count($keys);
                $stats->relationships += count($keys);
            }
        }
    }

    private function reverseEdge(Edge $edge): ?Edge
    {
        foreach ($this->analysis->graph->parentEdges($edge->parent) as $candidate) {
            if ($candidate->parent === $edge->child && count($candidate->columns) === 1) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Empties tables children-first. Deferred and self references are nulled first so
     * cycles don't block deletion. DELETE is used instead of TRUNCATE because
     * PostgreSQL's TRUNCATE ... CASCADE could empty tables that weren't confirmed.
     *
     * @param  list<string>  $tables
     */
    private function wipe(DependencyOrder $order, array $tables): void
    {
        $set = array_fill_keys($tables, true);

        foreach ($this->analysis->graph->edges() as $edge) {
            if (isset($set[$edge->child]) && $edge->nullable && ($edge->isSelfReferencing() || $order->isDeferred($edge))) {
                $this->db->table($edge->child)->update(array_fill_keys($edge->columns, null));
            }
        }

        foreach (array_reverse($order->tables) as $table) {
            if (isset($set[$table])) {
                $this->db->table($table)->delete();
            }
        }
    }

    /**
     * Checks integrity inside the transaction, so a failure rolls everything back.
     * Database constraints already enforce declared foreign keys; this also covers
     * relations that exist only in models, and polymorphic references.
     */
    private function verify(GenerationPlan $plan, DependencyOrder $order, GenerationStats $stats): void
    {
        $graph = $this->analysis->graph;

        foreach ($plan->generatedTables() as $table) {
            foreach ($graph->parentEdges($table) as $edge) {
                if (count($edge->columns) !== 1) {
                    continue;
                }

                $orphans = $this->db->table("{$edge->child} as c")
                    ->whereNotNull("c.{$edge->columns[0]}")
                    ->whereNotExists(fn ($query) => $query->selectRaw('1')->from("{$edge->parent} as p")->whereColumn("p.{$edge->parentColumns[0]}", "c.{$edge->columns[0]}"))
                    ->count();

                if ($orphans > 0) {
                    throw new GenerationException($table, $edge->columns[0], "{$orphans} rows reference missing [{$edge->parent}] rows");
                }
            }

            foreach ($graph->morphSlots($table) as $slot) {
                foreach ($slot->targets as $morphClass => $target) {
                    $key = $this->analysis->schema->table($target)->primaryKey[0];

                    $orphans = $this->db->table("{$table} as c")
                        ->where("c.{$slot->typeColumn}", $morphClass)
                        ->whereNotExists(fn ($query) => $query->selectRaw('1')->from("{$target} as p")->whereColumn("p.{$key}", "c.{$slot->idColumn}"))
                        ->count();

                    if ($orphans > 0) {
                        throw new GenerationException($table, $slot->idColumn, "{$orphans} rows reference missing [{$target}] rows");
                    }
                }
            }

            $schema = $this->analysis->schema->table($table);

            if ($schema->hasTimestamps() && $this->db->table($table)->whereColumn('created_at', '>', 'updated_at')->exists()) {
                throw new GenerationException($table, 'updated_at', 'rows were updated before they were created');
            }
        }

        $stats->checks = ['Foreign keys', 'Unique constraints', 'Enum values', 'Temporal consistency'];
    }

    private function resetSequence(TableSchema $schema): void
    {
        $key = $schema->primaryKey[0];

        $this->db->statement(
            "select setval(pg_get_serial_sequence(?, ?), coalesce((select max({$this->db->getQueryGrammar()->wrap($key)}) from {$this->db->getQueryGrammar()->wrapTable($schema->name)}), 1))",
            [$this->db->getTablePrefix().$schema->name, $key],
        );
    }

    private function chunkSize(int $columns): int
    {
        $budget = self::PARAMETER_BUDGET[$this->db->getDriverName()] ?? self::PARAMETER_BUDGET['default'];

        return max(1, min($this->maxChunk, intdiv($budget, max(1, $columns))));
    }
}
