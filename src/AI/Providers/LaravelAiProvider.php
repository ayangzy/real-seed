<?php

namespace Ayangzy\RealSeed\AI\Providers;

use Ayangzy\RealSeed\AI\AIProviderException;
use Ayangzy\RealSeed\AI\AIProviderInterface;
use Closure;
use Illuminate\Http\Client\RequestException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Throwable;

use function Laravel\Ai\agent;

/**
 * Uses the first-party Laravel AI SDK (laravel/ai), which supports Anthropic, OpenAI,
 * Gemini, Ollama, and other providers through the application's config/ai.php.
 */
final class LaravelAiProvider implements AIProviderInterface
{
    public function __construct(
        private readonly ?string $provider = null,
        private readonly ?string $model = null,
        private readonly int $timeout = 120,
    ) {
    }

    public function available(): bool
    {
        return function_exists('Laravel\Ai\agent');
    }

    public function name(): string
    {
        return 'laravel-ai:'.($this->provider ?? 'default').($this->model !== null ? '/'.$this->model : '');
    }

    public function generate(string $instructions, string $prompt, array $schema): array
    {
        if (! $this->available()) {
            throw new AIProviderException('The Laravel AI SDK is not installed. Run: composer require laravel/ai');
        }

        try {
            $response = agent(instructions: $instructions, schema: $this->schemaBuilder($schema))
                ->prompt($prompt, provider: $this->provider, model: $this->model, timeout: $this->timeout);
        } catch (Throwable $e) {
            throw new AIProviderException($this->explain($e, $instructions.$prompt), previous: $e);
        }

        $structured = method_exists($response, 'toArray') ? $response->toArray() : null;

        if (! is_array($structured)) {
            throw new AIProviderException('The AI response did not contain structured output.');
        }

        return $structured;
    }

    /**
     * The SDK reports every HTTP 429 as "rate limited", but providers use 429 both for
     * real rate limits and for accounts without credit. Surface the provider's own
     * explanation and a hint, so the developer knows which one it is.
     */
    private function explain(Throwable $e, string $sent): string
    {
        $message = 'The AI request failed: '.$e->getMessage();
        $details = null;

        for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof RequestException && $cause->response !== null) {
                $error = $cause->response->json('error');
                // OpenAI: message + code; Anthropic: message + type; Google: message + status.
                $label = $error['status'] ?? $error['code'] ?? $error['type'] ?? null;
                $details = is_array($error)
                    ? trim(($error['message'] ?? '').($label !== null ? " ({$label})" : ''))
                    : (is_string($error) ? $error : null);
                break;
            }
        }

        if ($details !== null && $details !== '') {
            $message .= ' Provider says: '.$details;
        }

        $lower = strtolower((string) $details);

        $hint = match (true) {
            // "quota" alone is ambiguous: Google reports free-tier rate limits as RESOURCE_EXHAUSTED ... quota.
            str_contains($lower, 'insufficient_quota') || str_contains($lower, 'billing') || str_contains($lower, 'credit balance')
                => ' The account has no API credit: add billing with your AI provider (API usage is billed separately from chat subscriptions).',
            str_contains($e->getMessage(), 'rate limited') || str_contains($lower, 'rate_limit') || str_contains($lower, 'resource_exhausted')
                => sprintf(' The prompt was about %s tokens; wait a minute and retry, or choose a model with higher limits (REALSEED_AI_MODEL).', number_format((int) (mb_strlen($sent) / 4))),
            default => '',
        };

        return $message.$hint;
    }

    /**
     * Translates a plain JSON Schema object into the Laravel JSON Schema builder.
     */
    private function schemaBuilder(array $schema): Closure
    {
        return fn (JsonSchema $builder) => $this->properties($builder, $schema);
    }

    private function properties(JsonSchema $builder, array $schema): array
    {
        $required = $schema['required'] ?? [];
        $properties = [];

        foreach ($schema['properties'] ?? [] as $name => $property) {
            $type = $this->type($builder, $property);
            $properties[$name] = in_array($name, $required, true) ? $type->required() : $type;
        }

        return $properties;
    }

    private function type(JsonSchema $builder, array $property): mixed
    {
        $types = (array) ($property['type'] ?? 'string');
        $nullable = in_array('null', $types, true);
        $kind = current(array_diff($types, ['null'])) ?: 'string';

        $type = match ($kind) {
            'object' => $builder->object(fn (JsonSchema $nested) => $this->properties($nested, $property)),
            'array' => $builder->array()->items($this->type($builder, $property['items'] ?? ['type' => 'string'])),
            'integer' => $builder->integer(),
            'number' => $builder->number(),
            'boolean' => $builder->boolean(),
            default => $builder->string(),
        };

        if (isset($property['enum'])) {
            $type = $type->enum($property['enum']);
        }

        if (isset($property['description'])) {
            $type = $type->description($property['description']);
        }

        return $nullable ? $type->nullable() : $type;
    }
}
