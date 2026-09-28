<?php

namespace AISeeder\Console\Commands;

use AISeeder\Environment\ConnectionSafetyCheck;
use AISeeder\Environment\EnvironmentGuard;
use AISeeder\Environment\TargetDatabase;
use AISeeder\Environment\UnsupportedEnvironmentException;
use Illuminate\Console\Command;

class AiSeedCommand extends Command
{
    protected $signature = 'ai:seed';

    protected $description = 'Generate realistic synthetic data for local, dev, development, or staging environments';

    public function handle(EnvironmentGuard $guard, ConnectionSafetyCheck $safety): int
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

        $this->components->warn('Project analysis and generation are not implemented yet.');

        return self::SUCCESS;
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
