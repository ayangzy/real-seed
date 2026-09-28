<?php

namespace Ayangzy\RealSeed\Environment;

/**
 * Heuristics that catch an allowed APP_ENV pointed at a database that looks like production.
 *
 * These checks can only add friction on top of EnvironmentGuard; nothing here can make
 * an unsupported environment runnable.
 */
final class ConnectionSafetyCheck
{
    public const OK = 'ok';

    public const REQUIRES_TYPED_CONFIRMATION = 'requires_typed_confirmation';

    public const BLOCKED = 'blocked';

    private const PRODUCTION_TOKENS = ['prod', 'production', 'live', 'prd'];

    /**
     * @return array{status: string, reason: ?string}
     */
    public function assess(TargetDatabase $target, string $environment): array
    {
        foreach (['database' => $target->database, 'host' => $target->host] as $label => $value) {
            if ($value !== null && $this->containsProductionToken($value)) {
                return [
                    'status' => self::BLOCKED,
                    'reason' => "The {$label} [{$value}] looks like a production {$label}.",
                ];
            }
        }

        if ($environment === 'local' && $target->host !== null && ! $this->isLocalHost($target->host)) {
            return [
                'status' => self::REQUIRES_TYPED_CONFIRMATION,
                'reason' => "The environment is [local] but the database host [{$target->host}] is not a local host.",
            ];
        }

        return ['status' => self::OK, 'reason' => null];
    }

    private function containsProductionToken(string $value): bool
    {
        // Sqlite databases are file paths; only the file name is meaningful.
        $value = strtolower(basename($value));

        $tokens = preg_split('/[^a-z0-9]+/', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_intersect($tokens, self::PRODUCTION_TOKENS) !== [];
    }

    private function isLocalHost(string $host): bool
    {
        $host = strtolower($host);

        if (in_array($host, ['localhost', '::1', 'host.docker.internal'], true)) {
            return true;
        }

        if (str_starts_with($host, '127.') || str_ends_with($host, '.test') || str_ends_with($host, '.local') || str_ends_with($host, '.localhost')) {
            return true;
        }

        // Dot-less names are Docker/Sail service names such as "mysql" or "pgsql".
        return ! str_contains($host, '.') && ! str_contains($host, ':');
    }
}
