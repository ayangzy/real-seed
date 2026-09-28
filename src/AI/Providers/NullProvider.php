<?php

namespace AISeeder\AI\Providers;

use AISeeder\AI\AIProviderException;
use AISeeder\AI\AIProviderInterface;

/**
 * Used when no AI driver is available; AI Seeder then runs in no-AI mode.
 */
final class NullProvider implements AIProviderInterface
{
    public function __construct(private readonly string $reason = 'No AI provider is configured.')
    {
    }

    public function available(): bool
    {
        return false;
    }

    public function name(): string
    {
        return 'none';
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function generate(string $instructions, string $prompt, array $schema): array
    {
        throw new AIProviderException($this->reason);
    }
}
