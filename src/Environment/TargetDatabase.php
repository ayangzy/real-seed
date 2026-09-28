<?php

namespace AISeeder\Environment;

use Illuminate\Contracts\Config\Repository;

/**
 * Describes the database a run would write to, built from configuration only.
 * No connection is opened and no query is executed.
 */
final readonly class TargetDatabase
{
    public function __construct(
        public string $connection,
        public string $driver,
        public string $database,
        public ?string $host,
    ) {
    }

    public static function fromConfig(Repository $config, ?string $connection = null): self
    {
        $connection ??= (string) $config->get('database.default');
        $settings = (array) $config->get("database.connections.{$connection}", []);

        $host = $settings['host'] ?? null;

        // Read/write split connections store hosts as arrays; the write host is the one we'd touch.
        if (isset($settings['write']['host'])) {
            $host = $settings['write']['host'];
        }

        if (is_array($host)) {
            $host = $host[0] ?? null;
        }

        return new self(
            connection: $connection,
            driver: (string) ($settings['driver'] ?? 'unknown'),
            database: (string) ($settings['database'] ?? ''),
            host: is_string($host) && $host !== '' ? $host : null,
        );
    }
}
