<?php

namespace Ayangzy\RealSeed\AI;

/**
 * A language model that returns structured output.
 *
 * Implementations only transport a prompt and a JSON Schema to a model and return
 * the decoded object. Everything returned is treated as untrusted suggestions and
 * validated against the discovered schema before it can influence generation.
 */
interface AIProviderInterface
{
    /**
     * Whether the provider can be used (package installed, driver configured).
     */
    public function available(): bool;

    /**
     * A stable identifier such as "laravel-ai:anthropic/claude-sonnet-5", used in the
     * plan cache key and shown to the developer.
     */
    public function name(): string;

    /**
     * @param  array<string, mixed>  $schema  A JSON Schema (draft 2020-12 subset) describing the expected object.
     * @return array<string, mixed>
     *
     * @throws AIProviderException
     */
    public function generate(string $instructions, string $prompt, array $schema): array;
}
