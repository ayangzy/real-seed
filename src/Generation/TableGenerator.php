<?php

namespace Ayangzy\RealSeed\Generation;

use Ayangzy\RealSeed\Extension\ExtensionRegistry;
use Ayangzy\RealSeed\Graph\Edge;
use Ayangzy\RealSeed\Planning\FieldPlan;
use Ayangzy\RealSeed\Planning\TablePlan;
use Ayangzy\RealSeed\Schema\ColumnSchema;
use Ayangzy\RealSeed\Schema\TableSchema;
use Ayangzy\RealSeed\Semantics\Semantic;
use Carbon\CarbonImmutable;
use Generator;

/**
 * Builds a table's rows in dependency-aware order and yields them in chunks.
 */
final class TableGenerator
{
    private const MAX_ATTEMPTS = 10;

    private const STRUCTURAL = [Semantic::KEY, Semantic::REFERENCE, Semantic::MORPH_TYPE, Semantic::MORPH_ID];

    /** In hybrid mode these stay with the engine: they carry distributions and timelines. */
    private const ENGINE_OWNED = [
        Semantic::ENUM, Semantic::CREATED_AT, Semantic::UPDATED_AT, Semantic::DELETED_AT, Semantic::PAST, Semantic::FUTURE,
    ];

    public const STRATEGY_AI = 'ai';

    public const STRATEGY_FACTORY = 'factory';

    public const STRATEGY_HYBRID = 'hybrid';

    public function __construct(
        private readonly ValueGenerator $values,
        private readonly TemporalGenerator $time,
        private readonly RelationshipResolver $relations,
        private readonly KeyAllocator $keys,
        private readonly UniqueTracker $unique,
        private readonly ?ExtensionRegistry $extensions = null,
    ) {
    }

    /**
     * @return Generator<int, list<array<string, mixed>>>
     */
    public function generate(TableSchema $schema, TablePlan $plan, SeededRandom $random, int $chunkSize, GenerationStats $stats, ?FactorySource $factory = null, string $strategy = self::STRATEGY_AI): Generator
    {
        $store = $this->relations->stores[$schema->name];
        $columns = $this->insertColumns($schema, $plan);
        $ordered = $this->orderedFields($schema, $plan);
        $key = $schema->singlePrimaryKey();
        $keyStrategy = $key === null ? null : (string) ($plan->field($key->name)?->option('strategy') ?? 'increment');
        $uniqueIndexes = UniqueTracker::indexes($schema, $keyStrategy !== 'increment');
        $sequenceStart = $store->count;
        $chunk = [];

        for ($i = 0; $i < $plan->count; $i++) {
            $keyValue = null;
            $row = null;

            for ($attempt = 0; $attempt < self::MAX_ATTEMPTS && $row === null; $attempt++) {
                $row = $this->buildRow($schema, $plan, $ordered, $key, $keyStrategy, $keyValue, $i, $sequenceStart + $i + 1, $random, $factory, $strategy);

                if (! $this->resolveConflicts($schema->name, $uniqueIndexes, $row, $i + 1)) {
                    $row = null;
                }
            }

            if ($row === null) {
                $stats->skipped[$schema->name] = ($stats->skipped[$schema->name] ?? 0) + 1;

                continue;
            }

            foreach ($uniqueIndexes as $index) {
                $this->unique->remember($schema->name, $index, $row->values);
            }

            $store->add($row->values, $row->time);
            $stats->generated[$schema->name] = ($stats->generated[$schema->name] ?? 0) + 1;
            $stats->relationships += $row->references;

            $chunk[] = array_map(fn (string $column) => $row->values[$column] ?? null, $columns);

            if (count($chunk) >= $chunkSize) {
                yield $this->withColumnNames($columns, $chunk);
                $chunk = [];
            }
        }

        if ($chunk !== []) {
            yield $this->withColumnNames($columns, $chunk);
        }
    }

    /**
     * @param  list<array{string, FieldPlan, ColumnSchema}>  $ordered
     */
    private function buildRow(TableSchema $schema, TablePlan $plan, array $ordered, ?ColumnSchema $key, ?string $keyStrategy, int|string|null &$keyValue, int $position, int $sequence, SeededRandom $random, ?FactorySource $factory, string $strategy): BuiltRow
    {
        $selfKey = function () use ($schema, $keyStrategy, $random, &$keyValue) {
            return $keyValue ??= $this->keys->next($schema->name, $keyStrategy ?? 'increment', $random, $this->time->start);
        };

        // Faker draws from PHP's global mt_rand state, which anything (even a destructed
        // Faker instance) can reseed. Seeding per row from this table's isolated stream
        // keeps every row reproducible regardless of what ran before it.
        $this->values->faker()->seed($random->int(0, 2_147_483_647));

        $resolved = $this->relations->resolve($schema->name, $plan, $selfKey, $random, $position);
        $time = $this->time->rowTime($resolved['after'], $random);
        $state = new RowState($schema->name, $time, $sequence);

        if ($key !== null) {
            $keyValue ??= $this->keys->next($schema->name, $keyStrategy, $random, $time);
            $state->set($key->name, Semantic::KEY, $keyValue);
        }

        $references = 0;

        foreach ($resolved['values'] as $column => $value) {
            $state->set($column, Semantic::REFERENCE, $value);
            $references += $value !== null && ! str_ends_with($column, '_type') ? 1 : 0;
        }

        $fromFactory = [];

        if ($factory !== null) {
            // Every reference is overridden (null where unresolved), so nested factories never run.
            $overrides = $state->values + array_fill_keys($this->relations->referenceColumns($schema->name), null);
            $fromFactory = $factory->attributes($overrides, $this->factoryState($plan, $factory, $random)) ?? [];
        }

        $fromCustom = [];

        if (($rowGenerator = $this->extensions?->rowGenerator($schema->name)) !== null) {
            $fromCustom = $rowGenerator->attributes(
                $state->values,
                CarbonImmutable::createFromTimestamp($time, date_default_timezone_get()),
                $this->values->faker(),
                $random,
            );
        }

        foreach ($ordered as [$column, $field, $columnSchema]) {
            if (array_key_exists($column, $fromCustom)) {
                $state->set($column, $field->semantic, $fromCustom[$column]);

                continue;
            }

            if (array_key_exists($column, $fromFactory) && $this->factoryWins($strategy, $field)) {
                $state->set($column, $field->semantic, $fromFactory[$column]);

                continue;
            }

            $value = $field->semantic === Semantic::UPDATED_AT
                ? CarbonImmutable::createFromTimestamp($this->time->updated($time, $state->latestTimestamp() ?? $time, $random), date_default_timezone_get())
                : $this->values->generate($field, $columnSchema, $state, $random);

            $state->set($column, $field->semantic, $value);
        }

        return new BuiltRow($state->values, $time, $references);
    }

    private function factoryWins(string $strategy, FieldPlan $field): bool
    {
        if ($strategy === self::STRATEGY_FACTORY) {
            return true;
        }

        return ! in_array($field->semantic, self::ENGINE_OWNED, true)
            && array_intersect_key($field->options, array_flip(['samples', 'weights', 'present_when', 'value'])) === [];
    }

    /**
     * Picks a factory state for the row from the plan's weights ("default" means no state).
     */
    private function factoryState(TablePlan $plan, FactorySource $factory, SeededRandom $random): ?string
    {
        $weights = (array) $plan->option('factory_states', []);

        if ($weights === []) {
            return null;
        }

        $state = (string) $random->weighted($weights);

        return in_array($state, $factory->info->states, true) ? $state : null;
    }

    /**
     * Repairs single-column text collisions with a numeric suffix; anything else is rebuilt.
     *
     * @param  list<list<string>>  $indexes
     */
    private function resolveConflicts(string $table, array $indexes, BuiltRow $row, int $suffix): bool
    {
        foreach ($indexes as $index) {
            if (! $this->unique->exists($table, $index, $row->values)) {
                continue;
            }

            $column = $index[0];
            $value = $row->values[$column] ?? null;

            if (count($index) !== 1 || ! is_string($value)) {
                return false;
            }

            $row->values[$column] = str_contains($value, '@')
                ? preg_replace('/@/', '.'.$suffix.'@', $value, 1)
                : $value.'-'.$suffix;

            if ($this->unique->exists($table, $index, $row->values)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Every row in a chunk must share the same columns in the same order for a bulk insert.
     *
     * @return list<string>
     */
    private function insertColumns(TableSchema $schema, TablePlan $plan): array
    {
        $referenceColumns = [];

        foreach ($this->relations->slots($schema->name) as $slot) {
            array_push($referenceColumns, ...($slot instanceof Edge ? $slot->columns : [$slot->typeColumn, $slot->idColumn]));
        }

        return array_values(array_filter(
            array_keys($schema->columns),
            fn (string $column) => ! $schema->columns[$column]->generated
                && ($plan->field($column) !== null || in_array($column, $referenceColumns, true) || in_array($column, $schema->primaryKey, true)),
        ));
    }

    /**
     * Non-structural fields in generation order: states before the timestamps that depend
     * on them, names before emails, creation before events, events before updated_at.
     *
     * @return list<array{string, FieldPlan, ColumnSchema}>
     */
    private function orderedFields(TableSchema $schema, TablePlan $plan): array
    {
        $fields = [];
        $position = 0;

        foreach ($plan->fields as $column => $field) {
            $columnSchema = $schema->column($column);

            if ($columnSchema === null || in_array($field->semantic, self::STRUCTURAL, true)) {
                continue;
            }

            $fields[] = [$this->priority($field->semantic, $column), $position++, [$column, $field, $columnSchema]];
        }

        usort($fields, fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return array_column($fields, 2);
    }

    private function priority(string $semantic, string $column): float
    {
        return match ($semantic) {
            Semantic::ENUM, Semantic::BOOLEAN => 1,
            Semantic::FIRST_NAME, Semantic::LAST_NAME, Semantic::COMPANY, Semantic::TITLE, Semantic::NAME => 2,
            Semantic::FULL_NAME => 3,
            Semantic::EMAIL, Semantic::USERNAME, Semantic::SLUG, Semantic::URL, Semantic::DOMAIN => 5,
            Semantic::CREATED_AT => 6,
            Semantic::PAST, Semantic::FUTURE, Semantic::BIRTH_DATE => preg_match('/(end|ends|finish|until|expires|expiry|closed|closes)(_at|_on|_date|_time)?$/', $column) ? 7.5 : 7,
            Semantic::DELETED_AT => 8,
            Semantic::UPDATED_AT => 9,
            default => 4,
        };
    }

    /**
     * @param  list<string>  $columns
     * @param  list<list<mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function withColumnNames(array $columns, array $rows): array
    {
        return array_map(fn (array $values) => array_combine($columns, $values), $rows);
    }
}
