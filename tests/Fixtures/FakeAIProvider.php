<?php

namespace Ayangzy\RealSeed\Tests\Fixtures;

use Ayangzy\RealSeed\AI\AIProviderException;
use Ayangzy\RealSeed\AI\AIProviderInterface;

final class FakeAIProvider implements AIProviderInterface
{
    /** @var list<array{instructions: string, prompt: string, schema: array}> */
    public array $calls = [];

    public function __construct(
        private readonly array|\Throwable $response = [],
    ) {
    }

    public function available(): bool
    {
        return true;
    }

    public function name(): string
    {
        return 'fake';
    }

    public function generate(string $instructions, string $prompt, array $schema): array
    {
        $this->calls[] = compact('instructions', 'prompt', 'schema');

        if ($this->response instanceof \Throwable) {
            throw new AIProviderException($this->response->getMessage());
        }

        return $this->response;
    }
}
