<?php

use Ayangzy\RealSeed\AI\AIProviderException;
use Ayangzy\RealSeed\AI\PlanPrompt;
use Ayangzy\RealSeed\AI\Providers\LaravelAiProvider;
use Laravel\Ai\AiServiceProvider;
use Laravel\Ai\StructuredAnonymousAgent;

beforeEach(function () {
    if (! class_exists(AiServiceProvider::class)) {
        $this->markTestSkipped('laravel/ai is not installed (it requires Laravel 12 or newer).');
    }

    $this->app->register(AiServiceProvider::class);
});

it('returns structured output through the Laravel AI SDK', function () {
    StructuredAnonymousAgent::fake([['domain' => 'CRM', 'timeline_months' => 6, 'tables' => []]]);

    $result = (new LaravelAiProvider('anthropic', 'claude-sonnet-5'))->generate('instructions', 'prompt', PlanPrompt::schema());

    expect($result)->toBe(['domain' => 'CRM', 'timeline_months' => 6, 'tables' => []]);

    StructuredAnonymousAgent::assertPrompted(fn ($prompt) => $prompt->prompt === 'prompt');
});

it('translates the plan schema into the SDK schema builder', function () {
    StructuredAnonymousAgent::fake(); // generates fake data from the translated schema

    $result = (new LaravelAiProvider)->generate('instructions', 'prompt', PlanPrompt::schema());

    expect($result)->toHaveKeys(['domain', 'timeline_months', 'tables']);
});

it('wraps SDK failures', function () {
    StructuredAnonymousAgent::fake(fn () => throw new RuntimeException('401 unauthorized'));

    (new LaravelAiProvider)->generate('instructions', 'prompt', PlanPrompt::schema());
})->throws(AIProviderException::class, '401 unauthorized');

it('names itself for the plan cache', function () {
    expect((new LaravelAiProvider('openai', 'gpt-5'))->name())->toBe('laravel-ai:openai/gpt-5')
        ->and((new LaravelAiProvider)->name())->toBe('laravel-ai:default');
});

function providerError(int $status, array $body): \Laravel\Ai\Exceptions\RateLimitedException
{
    $response = new \Illuminate\Http\Client\Response(new \GuzzleHttp\Psr7\Response($status, ['Content-Type' => 'application/json'], json_encode($body)));

    return \Laravel\Ai\Exceptions\RateLimitedException::forProvider('openai', $status, new \Illuminate\Http\Client\RequestException($response));
}

it('explains a 429 caused by an account without credit', function () {
    StructuredAnonymousAgent::fake(fn () => throw providerError(429, ['error' => [
        'message' => 'You exceeded your current quota, please check your plan and billing details.',
        'type' => 'insufficient_quota', 'code' => 'insufficient_quota',
    ]]));

    (new LaravelAiProvider)->generate('instructions', 'prompt', PlanPrompt::schema());
})->throws(AIProviderException::class, 'Provider says: You exceeded your current quota, please check your plan and billing details. (insufficient_quota) The account has no API credit');

it('explains a real rate limit with the prompt size', function () {
    StructuredAnonymousAgent::fake(fn () => throw providerError(429, ['error' => [
        'message' => 'Rate limit reached for requests per minute.', 'code' => 'rate_limit_exceeded',
    ]]));

    (new LaravelAiProvider)->generate('instructions', str_repeat('x', 4000), PlanPrompt::schema());
})->throws(AIProviderException::class, 'The prompt was about 1,003 tokens; wait a minute and retry');

it('treats a Gemini free-tier limit as a rate limit, not missing credit', function () {
    StructuredAnonymousAgent::fake(fn () => throw providerError(429, ['error' => [
        'code' => 429, 'message' => 'Resource has been exhausted (e.g. check quota).', 'status' => 'RESOURCE_EXHAUSTED',
    ]]));

    try {
        (new LaravelAiProvider('gemini'))->generate('instructions', 'prompt', PlanPrompt::schema());
    } catch (AIProviderException $e) {
        expect($e->getMessage())->toContain('Provider says: Resource has been exhausted (e.g. check quota). (RESOURCE_EXHAUSTED)')
            ->toContain('wait a minute and retry')
            ->not->toContain('no API credit');

        return;
    }

    $this->fail('Expected an AIProviderException.');
});

it('explains a timeout hidden behind "could not connect"', function () {
    StructuredAnonymousAgent::fake(fn () => throw \Laravel\Ai\Exceptions\ProviderConnectionException::forProvider(
        'gemini', 0, new \Illuminate\Http\Client\ConnectionException('cURL error 28: Operation timed out after 300001 milliseconds with 0 bytes received'),
    ));

    (new LaravelAiProvider('gemini', null, 300))->generate('instructions', 'prompt', PlanPrompt::schema());
})->throws(AIProviderException::class, 'Cause: cURL error 28: Operation timed out after 300001 milliseconds with 0 bytes received The AI took longer than 300 seconds to answer. Raise REALSEED_AI_TIMEOUT');

it('explains DNS failures', function () {
    StructuredAnonymousAgent::fake(fn () => throw \Laravel\Ai\Exceptions\ProviderConnectionException::forProvider(
        'gemini', 0, new \Illuminate\Http\Client\ConnectionException('cURL error 6: Could not resolve host: generativelanguage.googleapis.com'),
    ));

    (new LaravelAiProvider('gemini'))->generate('instructions', 'prompt', PlanPrompt::schema());
})->throws(AIProviderException::class, 'could not be resolved: check your internet connection or DNS');
