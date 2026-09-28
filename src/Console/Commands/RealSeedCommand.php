<?php

namespace Ayangzy\RealSeed\Console\Commands;

use Ayangzy\RealSeed\AI\AIProviderException;
use Ayangzy\RealSeed\AI\AIProviderInterface;
use Ayangzy\RealSeed\AI\ApplicationContext;
use Ayangzy\RealSeed\AI\PlanPrompt;
use Ayangzy\RealSeed\AI\Providers\NullProvider;
use Ayangzy\RealSeed\Analysis\ProjectAnalysis;
use Ayangzy\RealSeed\Analysis\ProjectAnalyzer;
use Ayangzy\RealSeed\Database\SeederExecutor;
use Ayangzy\RealSeed\Environment\ConnectionSafetyCheck;
use Ayangzy\RealSeed\Extension\ExtensionRegistry;
use Ayangzy\RealSeed\Extension\ScenarioProvider;
use Ayangzy\RealSeed\Environment\EnvironmentGuard;
use Ayangzy\RealSeed\Environment\TargetDatabase;
use Ayangzy\RealSeed\Environment\UnsupportedEnvironmentException;
use Ayangzy\RealSeed\Generation\GenerationStats;
use Ayangzy\RealSeed\Generation\TableGenerator;
use Ayangzy\RealSeed\Graph\CircularDependencyException;
use Ayangzy\RealSeed\Graph\DependencyOrder;
use Ayangzy\RealSeed\Graph\DependencyResolver;
use Ayangzy\RealSeed\Locale\LocaleProvider;
use Ayangzy\RealSeed\Locale\LocaleRegistry;
use Ayangzy\RealSeed\Planning\ConfigOverrides;
use Ayangzy\RealSeed\Planning\GenerationPlan;
use Ayangzy\RealSeed\Planning\HeuristicPlanner;
use Ayangzy\RealSeed\Planning\PlanningException;
use Ayangzy\RealSeed\Planning\PlanOptions;
use Ayangzy\RealSeed\Planning\PlanStore;
use Ayangzy\RealSeed\Planning\PlanValidator;
use Ayangzy\RealSeed\Validation\GenerationException;
use Illuminate\Console\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PDOException;
use RuntimeException;
use Throwable;

class RealSeedCommand extends Command
{
    protected $signature = 'real:seed
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
        {--locale= : Locale for names, addresses, phones and currency, e.g. ng, us, gb, de, or a Faker locale like pt_BR}
        {--strategy= : ai (default), factory (use model factories for values), or hybrid (factories for basic values, RealSeed for relationships, distributions and timelines)}';

    protected $description = 'Generate realistic synthetic data for local, dev, development, or staging environments';

    /** @var list<string> */
    protected $aliases = ['realseed', 'ai:seed'];

    /** @var list<string> */
    private array $planNotes = [];

    public function handle(EnvironmentGuard $guard, ConnectionSafetyCheck $safety, ProjectAnalyzer $analyzer, AIProviderInterface $ai, PlanStore $plans, LocaleRegistry $locales, ExtensionRegistry $extensions): int
    {
        $this->newLine();
        $this->line($this->option('dry-run') ? '<options=bold>RealSeed — Dry Run</>' : '<options=bold>RealSeed</>');
        $this->newLine();

        // The guard runs before any analysis, database access, or AI call.
        try {
            $environment = $guard->ensureSupported();
        } catch (UnsupportedEnvironmentException $e) {
            return $this->rejectEnvironment($e->environment);
        }

        $target = TargetDatabase::fromConfig($this->laravel['config'], $this->laravel['config']->get('realseed.connection'));

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
            $this->line('RealSeed refuses to write to databases that look like production.');

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
            $extensions->validate();
            $locale = $locales->resolve($options['locale']);
            $scenario = $options['scenario'] !== null ? $extensions->scenario($options['scenario']) : null;
        } catch (InvalidArgumentException $e) {
            $this->line("<fg=red>✗ {$e->getMessage()}</>");

            return $this->noChangesMade();
        }

        $analysis = $this->analyze($analyzer, $target);

        if ($analysis === null) {
            return $this->noChangesMade();
        }

        $connection = $this->laravel['db']->connection($target->connection);

        // Named scenarios come from code; only free-text scenarios need the AI.
        $useAi = $this->aiStatus($ai, $scenario === null ? $options['scenario'] : null);

        if ($useAi === null) {
            return $this->noChangesMade();
        }

        try {
            $order = (new DependencyResolver)->resolve($analysis->graph);
            $plan = $this->plan($analysis, $connection, $options, $useAi ? $ai : null, $plans, $extensions, $scenario);
        } catch (CircularDependencyException|PlanningException|AIProviderException|InvalidArgumentException $e) {
            $this->line("<fg=red>✗ {$e->getMessage()}</>");

            return $this->noChangesMade();
        }

        if ($plan->totalRows() === 0) {
            foreach ($this->planNotes as $note) {
                $this->line('<fg=yellow>! '.OutputFormatter::escape($note).'</>');
            }

            $this->line($this->planNotes === []
                ? 'Nothing to generate: every selected table can reuse existing rows.'
                : '<fg=red>✗ Nothing can be generated: every selected table was skipped for the reasons above.</>');

            return $this->noChangesMade($this->planNotes === [] ? self::SUCCESS : self::FAILURE);
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

        return $this->generate($connection, $analysis, $plan, $order, $wipe, $environment, $options['strategy'], $locale, $extensions);
    }

    /**
     * @return array{seed: int, size: string, count: ?int, only: ?list<string>, except: list<string>, fresh: bool, scenario: ?string, strategy: string, locale: string}
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

        $strategy = (string) ($this->option('strategy') ?? $this->laravel['config']->get('realseed.strategy', TableGenerator::STRATEGY_AI));

        if (! in_array($strategy, [TableGenerator::STRATEGY_AI, TableGenerator::STRATEGY_FACTORY, TableGenerator::STRATEGY_HYBRID], true)) {
            throw new InvalidArgumentException('--strategy must be ai, factory, or hybrid.');
        }

        $size = (string) ($this->option('size') ?? $this->laravel['config']->get('realseed.size', 'medium'));

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
            'locale' => (string) ($this->option('locale') ?? $this->laravel['config']->get('realseed.locale', 'en_US')),
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
                'model_paths' => $config->get('realseed.model_paths', []),
                'migration_paths' => [$this->laravel->databasePath('migrations'), ...$migrator->paths()],
                'excluded_tables' => $config->get('realseed.excluded_tables', []),
                'excluded_columns' => $config->get('realseed.excluded_columns', []),
                'morph_targets' => $config->get('realseed.morph_targets', []),
            ]);
        } catch (QueryException|PDOException $e) {
            $this->line('<fg=red>✗ Could not read the database schema.</>');
            $this->line($e->getMessage(), verbosity: 'v');

            return null;
        }

        if (! $analysis->migrations->isUpToDate()) {
            $this->line(sprintf(
                '<fg=red>✗ %d of %d migrations have not been run.</> RealSeed reads the migrated schema; run <options=bold>php artisan migrate</> first.',
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

        foreach ($analysis->notices as $notice) {
            $this->line('<fg=yellow>! '.OutputFormatter::escape($notice).'</>');
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
        $enabled = (bool) $this->laravel['config']->get('realseed.ai.enabled', true);

        $reason = match (true) {
            (bool) $this->option('no-ai') => 'off (--no-ai)',
            ! $enabled => 'off (disabled in config/realseed.php)',
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
     * Builds the plan in layers, each validated against the schema: heuristic baseline,
     * then application analyzers, then AI suggestions (cached so re-runs are free and
     * reproducible), then a named scenario, then config overrides, which always win.
     *
     * @param  array{seed: int, size: string, count: ?int, only: ?list<string>, except: list<string>, fresh: bool, scenario: ?string, strategy: string, locale: string}  $options
     */
    private function plan(ProjectAnalysis $analysis, Connection $connection, array $options, ?AIProviderInterface $ai, PlanStore $plans, ExtensionRegistry $extensions, ?ScenarioProvider $scenario): GenerationPlan
    {
        $config = $this->laravel['config'];
        $locale = strtolower($options['locale']);
        $scenarioText = $scenario !== null ? $scenario->description() : $options['scenario'];
        $planner = new HeuristicPlanner($analysis);
        $this->planNotes = [];

        $key = $plans->key([
            'schema' => $analysis->schema->hash(),
            'provider' => $ai?->name(),
            'scenario' => $scenarioText,
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
        $validator = new PlanValidator($analysis, (int) $config->get('realseed.max_rows', 250000));

        // Developer knowledge refines the baseline before the AI sees it...
        foreach ($extensions->analyzers() as $applicationAnalyzer) {
            $base = $this->mergeTrusted($validator, $base, $applicationAnalyzer->suggestions($analysis, $base), $planOptions);
        }

        $requireAi = $scenario === null && $options['scenario'] !== null;
        $plan = $this->aiPlan($analysis, $base, $planOptions, $ai, $plans, $key, $cached, $scenarioText, $validator, $requireAi);

        // ...and named scenarios and configuration have the final say.
        if ($scenario !== null) {
            $plan = $this->mergeTrusted($validator, $plan, $scenario->suggestions($analysis, $plan), $planOptions);
            $this->line('Scenario: '.OutputFormatter::escape($options['scenario']));
        }

        $overrides = (array) $config->get('realseed.overrides', []);

        if ($overrides !== []) {
            $plan = $this->mergeTrusted($validator, $plan, ConfigOverrides::toSuggestions($overrides), $planOptions);
        }

        // Final guarantee before anything is shown or written: every generated table
        // must have rows available in every table it requires.
        [$plan, $notes] = $planner->enforceDependencies($plan, $planOptions);
        array_push($this->planNotes, ...$notes);

        return $plan;
    }

    private function mergeTrusted(PlanValidator $validator, GenerationPlan $plan, array $suggestions, PlanOptions $options): GenerationPlan
    {
        $merged = $validator->merge($plan, $suggestions, $options, trusted: true);

        foreach ($validator->warnings() as $warning) {
            $this->line('<fg=yellow>! '.OutputFormatter::escape($warning).'</>');
        }

        return $merged;
    }

    /**
     * @param  array{anchor: \Carbon\CarbonImmutable, provider: string, domain: ?string, suggestions: array}|null  $cached
     */
    private function aiPlan(ProjectAnalysis $analysis, GenerationPlan $base, PlanOptions $planOptions, ?AIProviderInterface $ai, PlanStore $plans, string $key, ?array $cached, ?string $scenarioText, PlanValidator $validator, bool $requireAi): GenerationPlan
    {
        if ($this->option('show-prompt')) {
            $this->line('<options=bold>AI instructions</>');
            $this->line(OutputFormatter::escape(PlanPrompt::instructions()));
            $this->newLine();
            $this->line('<options=bold>AI prompt</>');
            $this->line(OutputFormatter::escape(PlanPrompt::prompt(new ApplicationContext($analysis, $base), $base, $scenarioText)));
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
                    PlanPrompt::prompt(new ApplicationContext($analysis, $base), $base, $scenarioText),
                    PlanPrompt::schema(),
                );

                $file = $plans->put($key, $base->end, $ai->name(), $scenarioText, $suggestions);
                $this->line("<fg=green>✓</> AI plan saved to {$file}");
            } catch (AIProviderException $e) {
                if ($requireAi) {
                    throw $e;
                }

                $this->line('<fg=yellow>! AI planning failed ('.OutputFormatter::escape($e->getMessage()).'); using built-in heuristics.</>');
                $this->newLine();

                return $base;
            }
        }

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
            ? ' (no factories found for the generated tables; RealSeed generates all values)'
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
    private function generate(Connection $connection, ProjectAnalysis $analysis, GenerationPlan $plan, DependencyOrder $order, array $wipe, string $environment, string $strategy, LocaleProvider $locale, ExtensionRegistry $extensions): int
    {
        $config = $this->laravel['config'];

        $executor = new SeederExecutor(
            db: $connection,
            analysis: $analysis,
            hashPassword: fn () => $this->laravel['hash']->make('password'),
            locale: $locale,
            extensions: $extensions,
            encrypter: $this->encrypter(),
            maxChunk: (int) $config->get('realseed.chunk_size', 500),
            existingRowsLimit: (int) $config->get('realseed.existing_rows_limit', 100000),
            strategy: $strategy,
            appLocale: (string) $config->get('app.locale', 'en'),
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
        $this->line('<options=bold>RealSeed Complete</>');
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
        $this->line("Reproduce with: php artisan real:seed --seed={$plan->seed}", verbosity: 'v');
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
        $this->line('<fg=red>✗ RealSeed cannot run in this environment.</>');
        $this->newLine();
        $this->line('RealSeed only supports:');

        foreach (EnvironmentGuard::ALLOWED_ENVIRONMENTS as $allowed) {
            $this->line("- {$allowed}");
        }

        return $this->noChangesMade();
    }

    private function confirmByTypingDatabaseName(TargetDatabase $target, string $reason): bool
    {
        $this->line("<fg=yellow>! {$reason}</>");

        if (! $this->input->isInteractive()) {
            $this->line('Confirmation is required, so RealSeed cannot continue in non-interactive mode.');

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
