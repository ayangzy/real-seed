<?php

use Ayangzy\RealSeed\AI\AIProviderException;
use Ayangzy\RealSeed\AI\PlanPrompt;
use Ayangzy\RealSeed\AI\Providers\LaravelAiProvider;
use Laravel\Ai\AiServiceProvider;
use Laravel\Ai\StructuredAnonymousAgent;

beforeEach(fn () => $this->app->register(AiServiceProvider::class));

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
