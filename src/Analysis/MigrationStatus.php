<?php

namespace AISeeder\Analysis;

use Illuminate\Database\Migrations\Migrator;
use Throwable;

final readonly class MigrationStatus
{
    /**
     * @param  list<string>  $pending
     */
    public function __construct(
        public int $total,
        public array $pending,
        public bool $repositoryMissing,
    ) {
    }

    /**
     * The analysis reads the live schema, so it is only meaningful once migrations have run.
     *
     * @param  list<string>  $paths
     */
    public static function inspect(Migrator $migrator, string $connection, array $paths): self
    {
        $files = array_keys($migrator->getMigrationFiles($paths));

        if ($files === []) {
            return new self(0, [], false);
        }

        return $migrator->usingConnection($connection, function () use ($migrator, $files) {
            try {
                if (! $migrator->repositoryExists()) {
                    return new self(count($files), $files, true);
                }

                $ran = $migrator->getRepository()->getRan();
            } catch (Throwable) {
                return new self(count($files), $files, true);
            }

            return new self(count($files), array_values(array_diff($files, $ran)), false);
        });
    }

    public function isUpToDate(): bool
    {
        return $this->pending === [] && ! $this->repositoryMissing;
    }
}
