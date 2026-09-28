<?php

namespace Ayangzy\RealSeed\Environment;

use RuntimeException;

final class UnsupportedEnvironmentException extends RuntimeException
{
    public function __construct(public readonly ?string $environment)
    {
        parent::__construct(sprintf(
            'RealSeed cannot run in the [%s] environment. It only supports: %s.',
            $environment ?? 'unknown',
            implode(', ', EnvironmentGuard::ALLOWED_ENVIRONMENTS),
        ));
    }
}
