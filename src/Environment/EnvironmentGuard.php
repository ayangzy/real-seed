<?php

namespace Ayangzy\RealSeed\Environment;

use Illuminate\Contracts\Foundation\Application;

/**
 * The package's security boundary.
 *
 * The allowlist is deliberately a constant and is never read from config,
 * environment variables, or command options. Adding an environment is a
 * package-level change, not a runtime decision.
 */
final class EnvironmentGuard
{
    public const ALLOWED_ENVIRONMENTS = [
        'local',
        'dev',
        'development',
        'staging',
    ];

    public function __construct(private readonly Application $app)
    {
    }

    /**
     * @throws UnsupportedEnvironmentException
     */
    public function ensureSupported(): string
    {
        $environment = $this->currentEnvironment();

        if ($environment === null || ! in_array($environment, self::ALLOWED_ENVIRONMENTS, true)) {
            throw new UnsupportedEnvironmentException($environment);
        }

        return $environment;
    }

    public function isSupported(): bool
    {
        $environment = $this->currentEnvironment();

        return $environment !== null && in_array($environment, self::ALLOWED_ENVIRONMENTS, true);
    }

    /**
     * Returns null when the environment cannot be determined reliably.
     */
    public function currentEnvironment(): ?string
    {
        $environment = $this->app->bound('env') ? $this->app['env'] : null;

        return is_string($environment) && $environment !== '' ? $environment : null;
    }
}
