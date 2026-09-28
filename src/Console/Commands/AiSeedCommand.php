<?php

namespace AISeeder\Console\Commands;

use AISeeder\Analysis\ProjectAnalysis;
use AISeeder\Analysis\ProjectAnalyzer;
use AISeeder\Environment\ConnectionSafetyCheck;
use AISeeder\Environment\EnvironmentGuard;
use AISeeder\Environment\TargetDatabase;
use AISeeder\Environment\UnsupportedEnvironmentException;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use PDOException;

class AiSeedCommand extends Command
{
    protected $signature = 'ai:seed';

    protected $description = 'Generate realistic synthetic data for local, dev, development, or staging environments';

    public function handle(EnvironmentGuard $guard, ConnectionSafetyCheck $safety, ProjectAnalyzer $analyzer): int
    {
        $this->newLine();
        $this->line('<options=bold>AI Seeder</>');
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

        $analysis = $this->analyze($analyzer, $target);

        if ($analysis === null) {
            return $this->noChangesMade();
        }

        $this->components->warn('Data generation is not implemented yet.');

        return self::SUCCESS;
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

    private function noChangesMade(): int
    {
        $this->newLine();
        $this->line('No database changes were made.');

        return self::FAILURE;
    }
}
