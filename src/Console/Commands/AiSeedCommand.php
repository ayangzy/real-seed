<?php

namespace AISeeder\Console\Commands;

use AISeeder\AI\AIProviderException;
use AISeeder\AI\AIProviderInterface;
use AISeeder\AI\ApplicationContext;
use AISeeder\AI\PlanPrompt;
use AISeeder\AI\Providers\NullProvider;
use AISeeder\Analysis\ProjectAnalysis;
use AISeeder\Analysis\ProjectAnalyzer;
use AISeeder\Database\SeederExecutor;
use AISeeder\Environment\ConnectionSafetyCheck;
use AISeeder\Environment\EnvironmentGuard;
use AISeeder\Environment\TargetDatabase;
use AISeeder\Environment\UnsupportedEnvironmentException;
use AISeeder\Generation\GenerationStats;
use AISeeder\Generation\TableGenerator;
use AISeeder\Graph\CircularDependencyException;
use AISeeder\Graph\DependencyOrder;
use AISeeder\Graph\DependencyResolver;
use AISeeder\Planning\GenerationPlan;
use AISeeder\Planning\HeuristicPlanner;
use AISeeder\Planning\PlanningException;
use AISeeder\Planning\PlanOptions;
use AISeeder\Planning\PlanStore;
use AISeeder\Planning\PlanValidator;
use AISeeder\Validation\GenerationException;
use Faker\Factory as FakerFactory;
use Illuminate\Console\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PDOException;
use RuntimeException;
use Throwable;

class AiSeedCommand extends Command
{
    protected $signature = 'ai:seed
        {--dry-run : Show the generation plan without changing the database}
        {--seed= : Seed for reproducible output}
        {--size= : Dataset size: small, medium, or large}
        {--count= : Approximate total number of rows to generate}
        {--only= : Comma-separated tables to generate (missing dependencies are added)}
        {--except= : Comma-separated tables to leave untouched}
        {--fresh : Delete existing rows in the affected tables first}
        {--scenario= : Describe the data you want, e.g. "busy clinic with six months of history" (requires AI)}
        {--no-ai : Plan with built-in heuristics only}
        {--replan : Ask the AI for a new plan instead of reusing the cached one}
        {--show-prompt : Print exactly what would be sent to the AI}
        {--strategy= : ai (default), factory (use model factories for values), or hybrid (factories for basic values, AI Seeder for relationships, distributions and timelines)}';

    protected $description = 'Generate realistic synthetic data for local, dev, development, or staging environments';

    /** @var list<string> */
    private array $planNotes = [];

    public function handle(EnvironmentGuard $guard, ConnectionSafetyCheck $safety, ProjectAnalyzer $analyzer, AIProviderInterface $ai, PlanStore $plans): int
    {
        $this->newLine();
        $this->line($this->option('dry-run') ? '<options=bold>AI Seeder — Dry Run</>' : '<options=bold>AI Seeder</>');
        $this->newLine();

        // The guard runs before any analysis, database access, or AI call.
        try {
            $environment = $guard->ensureSupported();
        } catch (UnsupportedEnvironmentException $e) {
            return $this->rejectEnvironment($e->environment);
        }

        $target = TargetDatabase::fromConfig($this->laravel['config'], $this->laravel['config']->get('ai-seeder.connection'));

        $this->line("Environment: {$environment}");
        $this->line("Database: {$target->database}");
        $this->line("Connection: {$target->connection} ({$target->driver})");

        if ($target->host !== null) {
            $this->line("Host: {$target->host}");
        }

        $this->newLine();

        $assessment = $safety->assess($target, $environment);

        if ($assessment['status'] === ConnectionSafetyCheck::BLOCKED) {
            $this->line("<fg=red>✗ {$assessment['reason']}</>");
            $this->line('AI Seeder refuses to write to databases that look like production.');

            return $this->noChangesMade();
        }

        if ($assessment['status'] === ConnectionSafetyCheck::REQUIRES_TYPED_CONFIRMATION
            && ! $this->confirmByTypingDatabaseName($target, $assessment['reason'])) {
            return $this->noChangesMade();
        }

        $this->line('<fg=green>✓ Environment supported</>');
        $this->line('<fg=green>✓ Production protection active</>');
        $this->newLine();

        try {
            $options = $this->parseOptions();
        } catch (InvalidArgumentException $e) {
            $this->line("<fg=red>✗ {$e->getMessage()}</>");

            return $this->noChangesMade();
        }

        $analysis = $this->analyze($analyzer, $target);

        if ($analysis === null) {
            return $this->noChangesMade();
        }

        $connection = $this->laravel['db']->connection($target->connection);

        $useAi = $this->aiStatus($ai, $options['scenario']);

        if ($useAi === null) {
            return $this->noChangesMade();
        }

        try {
            $order = (new DependencyResolver)->resolve($analysis->graph);
            $plan = $this->plan($analysis, $connection, $options, $useAi ? $ai : null, $plans);
        } catch (CircularDependencyException|PlanningException|AIProviderException $e) {
            $this->line("<fg=red>✗ {$e->getMessage()}</>");

            return $this->noChangesMade();
        }

        if ($plan->totalRows() === 0) {
            $this->line('Nothing to generate: every selected table can reuse existing rows.');

            return $this->noChangesMade(self::SUCCESS);
        }

        $this->showPlan($plan, $order, $this->planNotes, $options['strategy'], $analysis);

        $wipe = $options['fresh'] ? $this->tablesToWipe($analysis, $plan) : [];

        if ($wipe !== []) {
            $this->showWipe($connection, $wipe);
        }

        if ($this->option('dry-run')) {
            return $this->noChangesMade(self::SUCCESS);
        }

        if (! $this->confirmExecution($target, $wipe !== [])) {
            return $this->noChangesMade();
        }

        return $this->generate($connection, $analysis, $plan, $order, $wipe, $environment, $options['strategy']);
    }

    /**
     * @return array{seed: int, size: string, count: ?int, only: ?list<string>, except: list<string>, fresh: bool, scenario: ?string, strategy: string}
     */
    private function parseOptions(): array
    {
        $only = $this->listOption('only');
        $except = $this->listOption('except') ?? [];

        if ($only !== null && $except !== []) {
            throw new InvalidArgumentException('Use either --only or --except, not both.');
        }

        $count = $this->option('count');

        if ($count !== null && (! ctype_digit((string) $count) || (int) $count < 1)) {
            throw new InvalidArgumentException('--count must be a positive whole number.');
        }

        $seed = $this->option('seed');

        if ($seed !== null && ! preg_match('/^-?\d+$/', (string) $seed)) {
            throw new InvalidArgumentException('--seed must be a whole number.');
        }

        $strategy = (string) ($this->option('strategy') ?? $this->laravel['config']->get('ai-seeder.strategy', TableGenerator::STRATEGY_AI));

        if (! in_array($strategy, [TableGenerator::STRATEGY_AI, TableGenerator::STRATEGY_FACTORY, TableGenerator::STRATEGY_HYBRID], true)) {
            throw new InvalidArgumentException('--strategy must be ai, factory, or hybrid.');
        }

        $size = (string) ($this->option('size') ?? $this->laravel['config']->get('ai-seeder.size', 'medium'));

        if (! in_array($size, ['small', 'medium', 'large'], true)) {
            throw new InvalidArgumentException('--size must be small, medium, or large.');
        }

        return [
            'seed' => $seed !== null ? (int) $seed : random_int(1, 999_999),
            'size' => $size,
            'count' => $count !== null ? (int) $count : null,
            'only' => $only,
            'except' => $except,
            'fresh' => (bool) $this->option('fresh'),
            'scenario' => trim((string) $this->option('scenario')) ?: null,
            'strategy' => $strategy,
        ];
    }

    /**
     * @return list<string>|null
     */
    private function listOption(string $name): ?array
    {
        $value = $this->option($name);

        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return array_values(array_filter(array_map('trim', explode(',', (string) $value))));
    }

    private function analyze(ProjectAnalyzer $analyzer, TargetDatabase $target): ?ProjectAnalysis
    {
        $config = $this->laravel['config'];
        $migrator = $this->laravel['migrator'];

        try {
            $analysis = $analyzer->analyze($this->laravel['db']->connection($target->connection), $migrator, [
                'model_paths' => $config->get('ai-seeder.model_paths', []),
                'migration_paths' => [$this->laravel->databasePath('migrations'), ...$migrator->paths()],
                'excluded_tables' => $config->get('ai-seeder.excluded_tables', []),
                'excluded_columns' => $config->get('ai-seeder.excluded_columns', []),
            ]);
        } catch (QueryException|PDOException $e) {
            $this->line('<fg=red>✗ Could not read the database schema.</>');
            $this->line($e->getMessage(), verbosity: 'v');

            return null;
        }

        if (! $analysis->migrations->isUpToDate()) {
            $this->line(sprintf(
                '<fg=red>✗ %d of %d migrations have not been run.</> AI Seeder reads the migrated schema; run <options=bold>php artisan migrate</> first.',
                count($analysis->migrations->pending),
                $analysis->migrations->total,
            ));

            return null;
        }

        $graph = $analysis->graph;

        $this->line("<fg=green>✓</> {$analysis->migrations->total} migrations detected");
        $this->line('<fg=green>✓</> '.count($analysis->models).' models detected');
        $this->line('<fg=green>✓</> '.count($analysis->schema->tables).' tables detected');
        $this->line("<fg=green>✓</> {$graph->relationshipCount()} relationships detected");
        $this->line('<fg=green>✓</> '.count($analysis->enums).' enums detected');
        $this->line('<fg=green>✓</> '.count($analysis->factories).' factories detected');
        $this->newLine();

        if ($graph->entityTables() !== []) {
            $this->line('Detected application structures:');
            $this->newLine();

            foreach ($graph->entityTables() as $table) {
                $this->line(Str::headline($table));
            }

            $this->newLine();
        }

        foreach ($analysis->warnings as $warning) {
            $this->line("<fg=yellow>! {$warning}</>", verbosity: 'v');
        }

        return $analysis;
    }

    /**
     * Reports whether AI planning will be used. Returns null when the run can't continue
     * (a scenario was requested but no AI is available).
     */
    private function aiStatus(AIProviderInterface $ai, ?string $scenario): ?bool
    {
        $enabled = (bool) $this->laravel['config']->get('ai-seeder.ai.enabled', true);

        $reason = match (true) {
            (bool) $this->option('no-ai') => 'off (--no-ai)',
            ! $enabled => 'off (disabled in config/ai-seeder.php)',
            ! $ai->available() => 'unavailable ('.($ai instanceof NullProvider ? $ai->reason() : 'install laravel/ai to enable it').')',
            default => null,
        };

        if ($reason === null) {
            $this->line('AI planning: '.$ai->name());
            $this->newLine();

            return true;
        }

        if ($scenario !== null) {
            $this->line("<fg=red>✗ --scenario needs AI planning, which is {$reason}.</>");

            return null;
        }

        $this->line("AI planning: {$reason} — using built-in heuristics");
        $this->newLine();

        return false;
    }

    /**
     * Builds the heuristic baseline and, when AI is available, merges the AI's validated
     * suggestions onto it. Suggestions are cached so re-runs are free and reproducible.
     *
     * @param  array{seed: int, size: string, count: ?int, only: ?list<string>, except: list<string>, fresh: bool, scenario: ?string, strategy: string}  $options
     */
    private function plan(ProjectAnalysis $analysis, Connection $connection, array $options, ?AIProviderInterface $ai, PlanStore $plans): GenerationPlan
    {
        $config = $this->laravel['config'];
        $locale = (string) $config->get('ai-seeder.locale', 'en_US');
        $planner = new HeuristicPlanner($analysis);
        $this->planNotes = [];

        $key = $plans->key([
            'schema' => $analysis->schema->hash(),
            'provider' => $ai?->name(),
            'scenario' => $options['scenario'],
            'size' => $options['size'],
            'count' => $options['count'],
            'only' => $options['only'],
            'except' => $options['except'],
            'locale' => $locale,
        ]);

        $cached = $ai !== null && ! $this->option('replan') ? $plans->get($key) : null;

        $planOptions = new PlanOptions(
            seed: $options['seed'],
            locale: $locale,
            size: $options['size'],
            count: $options['count'],
            only: $options['only'],
            except: $options['except'],
            existingCounts: $this->existingCounts($connection, $analysis),
            now: $cached['anchor'] ?? null,
            scenario: $options['scenario'],
            fresh: $options['fresh'],
        );

        $base = $planner->plan($planOptions);
        $this->planNotes = $planner->notes();

        if ($this->option('show-prompt')) {
            $this->line('<options=bold>AI instructions</>');
            $this->line(OutputFormatter::escape(PlanPrompt::instructions()));
            $this->newLine();
            $this->line('<options=bold>AI prompt</>');
            $this->line(OutputFormatter::escape(PlanPrompt::prompt(new ApplicationContext($analysis, $base), $base, $options['scenario'])));
            $this->newLine();
        }

        if ($ai === null || $base->totalRows() === 0) {
            return $base;
        }

        if ($cached !== null) {
            $suggestions = $cached['suggestions'];
            $this->line('<fg=green>✓</> Reusing the saved AI plan ('.$plans->file($key).'). Use --replan for a new one.');
        } else {
            try {
                $this->line('Asking the AI to plan realistic data...');

                $suggestions = $ai->generate(
                    PlanPrompt::instructions(),
                    PlanPrompt::prompt(new ApplicationContext($analysis, $base), $base, $options['scenario']),
                    PlanPrompt::schema(),
                );

                $file = $plans->put($key, $base->end, $ai->name(), $options['scenario'], $suggestions);
                $this->line("<fg=green>✓</> AI plan saved to {$file}");
            } catch (AIProviderException $e) {
                if ($options['scenario'] !== null) {
                    throw $e;
                }

                $this->line('<fg=yellow>! AI planning failed ('.OutputFormatter::escape($e->getMessage()).'); using built-in heuristics.</>');
                $this->newLine();

                return $base;
            }
        }

        $validator = new PlanValidator($analysis, (int) $config->get('ai-seeder.max_rows', 250000));
        $plan = $validator->merge($base, $suggestions, $planOptions);

        if (is_string($suggestions['domain'] ?? null)) {
            $this->line('Application: '.OutputFormatter::escape(Str::limit(trim(preg_replace('/\s+/', ' ', $suggestions['domain'])), 200)));
        }

        foreach ($validator->warnings() as $warning) {
            $this->line('<fg=yellow>! '.OutputFormatter::escape($warning).'</>', verbosity: 'v');
        }

        $this->newLine();

        return $plan;
    }

    /**
     * @return array<string, int>
     */
    private function existingCounts(Connection $connection, ProjectAnalysis $analysis): array
    {
        $counts = [];

        foreach ($analysis->schema->names() as $table) {
            $counts[$table] = $connection->table($table)->count();
        }

        return $counts;
    }

    /**
     * @param  list<string>  $notes
     */
    private function showPlan(GenerationPlan $plan, DependencyOrder $order, array $notes, string $strategy, ProjectAnalysis $analysis): void
    {
        $this->line('<options=bold>Generation Plan</>');
        $this->newLine();

        $width = max(array_map(fn (string $table) => mb_strlen(Str::headline($table)), $plan->generatedTables())) + 2;

        foreach ($order->tables as $table) {
            if (($count = $plan->count($table)) > 0) {
                $this->line(str_pad(Str::headline($table).':', $width + 1).str_pad(number_format($count), 8, ' ', STR_PAD_LEFT));
            }
        }

        $this->newLine();
        $this->line('Relationships validated: <fg=green>✓</>');
        $this->line('Circular dependencies:   '.($order->hasCycles()
            ? count($order->deferred).' resolved by back-filling ('.implode(', ', array_map(fn ($edge) => $edge->child.'.'.$edge->columns[0], $order->deferred)).')'
            : 'none'));
        $this->line("Seed:                    {$plan->seed}");
        $this->line('Timeline:                '.$plan->start->toDateString().' → '.$plan->end->toDateString());
        $this->line("Strategy:                {$strategy}".$this->factorySummary($strategy, $plan, $analysis));

        foreach ($notes as $note) {
            $this->line("<fg=yellow>! {$note}</>");
        }

        $this->newLine();
    }

    private function factorySummary(string $strategy, GenerationPlan $plan, ProjectAnalysis $analysis): string
    {
        if ($strategy === TableGenerator::STRATEGY_AI) {
            return '';
        }

        $tables = array_values(array_filter($plan->generatedTables(), fn (string $table) => $analysis->factory($table) !== null));

        return $tables === []
            ? ' (no factories found for the generated tables; AI Seeder generates all values)'
            : ' (factories for '.implode(', ', $tables).')';
    }

    /**
     * Generated tables plus every table that references them, since emptying a parent
     * would otherwise orphan its children.
     *
     * @return list<string>
     */
    private function tablesToWipe(ProjectAnalysis $analysis, GenerationPlan $plan): array
    {
        $graph = $analysis->graph;
        $wipe = array_fill_keys($plan->generatedTables(), true);
        $queue = array_keys($wipe);

        while ($queue !== []) {
            $table = array_shift($queue);
            $dependents = array_map(fn ($edge) => $edge->child, $graph->childEdges($table));

            foreach ($graph->morphSlots() as $slot) {
                if (in_array($table, $slot->targets, true)) {
                    $dependents[] = $slot->table;
                }
            }

            foreach ($dependents as $dependent) {
                if (! isset($wipe[$dependent])) {
                    $wipe[$dependent] = true;
                    $queue[] = $dependent;
                }
            }
        }

        $tables = array_keys($wipe);
        sort($tables);

        return $tables;
    }

    /**
     * @param  list<string>  $tables
     */
    private function showWipe(Connection $connection, array $tables): void
    {
        $this->line('<fg=yellow;options=bold>--fresh: existing data in these tables will be removed:</>');

        foreach ($tables as $table) {
            $this->line(sprintf('  - %s (%s rows)', $table, number_format($connection->table($table)->count())));
        }

        $this->newLine();
    }

    private function confirmExecution(TargetDatabase $target, bool $destructive): bool
    {
        if ($destructive) {
            if (! $this->input->isInteractive()) {
                $this->line('<fg=red>✗ --fresh deletes data and must be confirmed interactively.</>');

                return false;
            }

            $answer = $this->ask("This will permanently delete the rows listed above. Type the database name [{$target->database}] to continue");

            if ($answer !== $target->database) {
                $this->line('<fg=red>✗ The database name did not match.</>');

                return false;
            }

            return true;
        }

        // Non-interactive runs (e.g. a staging deploy script) are additive only, so they proceed.
        if (! $this->input->isInteractive()) {
            return true;
        }

        return $this->confirm('This operation will generate synthetic data. Continue?', true);
    }

    /**
     * @param  list<string>  $wipe
     */
    private function generate(Connection $connection, ProjectAnalysis $analysis, GenerationPlan $plan, DependencyOrder $order, array $wipe, string $environment, string $strategy): int
    {
        $config = $this->laravel['config'];
        $country = strtoupper(explode('_', $plan->locale)[1] ?? 'US');

        $executor = new SeederExecutor(
            db: $connection,
            analysis: $analysis,
            faker: FakerFactory::create($plan->locale),
            hashPassword: fn () => $this->laravel['hash']->make('password'),
            encrypter: $this->encrypter(),
            maxChunk: (int) $config->get('ai-seeder.chunk_size', 500),
            existingRowsLimit: (int) $config->get('ai-seeder.existing_rows_limit', 100000),
            localeDefaults: [
                'country' => $country,
                'currency' => (string) $config->get('ai-seeder.currency', 'USD'),
                'app_locale' => (string) $config->get('app.locale', 'en'),
            ],
            strategy: $strategy,
        );

        $bar = $this->output->createProgressBar($plan->totalRows());
        $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %message%');
        $bar->setMessage('');
        $completed = [];

        $executor->onProgress(function (string $table, int $done) use ($bar, &$completed) {
            $completed[$table] = $done;
            $bar->setMessage(Str::headline($table));
            $bar->setProgress(array_sum($completed));
        });

        try {
            $stats = $executor->run($plan, $order, $wipe);
        } catch (GenerationException|QueryException|RuntimeException $e) {
            $bar->clear();
            $this->newLine();
            $this->line('<fg=red>✗ Generation failed:</> '.$e->getMessage());
            $this->line('The transaction was rolled back.');

            return $this->noChangesMade();
        }

        $bar->finish();
        $this->newLine(2);
        $this->showSummary($stats, $order, $environment, $plan);

        return self::SUCCESS;
    }

    private function showSummary(GenerationStats $stats, DependencyOrder $order, string $environment, GenerationPlan $plan): void
    {
        $this->line('<options=bold>AI Seeder Complete</>');
        $this->newLine();
        $this->line("Environment: {$environment}");
        $this->newLine();
        $this->line('Generated:');
        $this->newLine();

        $width = max(array_map(fn (string $table) => mb_strlen(Str::headline($table)), array_keys($stats->generated))) + 4;

        foreach ($order->tables as $table) {
            if (($count = $stats->generated[$table] ?? 0) > 0) {
                $this->line(str_pad(Str::headline($table), $width).str_pad(number_format($count), 8, ' ', STR_PAD_LEFT));
            }
        }

        $this->newLine();
        $this->line('Relationships:');
        $this->line('<fg=green>✓</> '.number_format($stats->relationships).' relationships created');
        $this->newLine();
        $this->line('Validation:');

        foreach ($stats->checks as $check) {
            $this->line("<fg=green>✓</> {$check}");
        }

        foreach ($stats->notes as $note) {
            $this->line('<fg=yellow>! '.OutputFormatter::escape($note).'</>');
        }

        foreach ($stats->skipped as $table => $skipped) {
            $this->line("<fg=yellow>! {$skipped} {$table} rows skipped after repeated unique-constraint collisions</>");
        }

        $this->newLine();
        $this->line(sprintf('Generation time: %.2fs', $stats->seconds));
        $this->line("Reproduce with: php artisan ai:seed --seed={$plan->seed}", verbosity: 'v');
    }

    private function encrypter(): mixed
    {
        try {
            return $this->laravel['encrypter'];
        } catch (Throwable) {
            return null; // No APP_KEY: only matters for models with encrypted casts.
        }
    }

    private function rejectEnvironment(?string $environment): int
    {
        $this->line('Environment: '.($environment ?? 'unknown'));
        $this->newLine();
        $this->line('<fg=red>✗ AI Seeder cannot run in this environment.</>');
        $this->newLine();
        $this->line('AI Seeder only supports:');

        foreach (EnvironmentGuard::ALLOWED_ENVIRONMENTS as $allowed) {
            $this->line("- {$allowed}");
        }

        return $this->noChangesMade();
    }

    private function confirmByTypingDatabaseName(TargetDatabase $target, string $reason): bool
    {
        $this->line("<fg=yellow>! {$reason}</>");

        if (! $this->input->isInteractive()) {
            $this->line('Confirmation is required, so AI Seeder cannot continue in non-interactive mode.');

            return false;
        }

        $answer = $this->ask("Type the database name [{$target->database}] to continue");

        if ($answer !== $target->database) {
            $this->line('<fg=red>✗ The database name did not match.</>');

            return false;
        }

        return true;
    }

    private function noChangesMade(int $status = self::FAILURE): int
    {
        $this->newLine();
        $this->line('No database changes were made.');

        return $status;
    }
}
