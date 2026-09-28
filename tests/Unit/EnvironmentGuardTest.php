<?php

use Ayangzy\RealSeed\Environment\EnvironmentGuard;
use Ayangzy\RealSeed\Environment\UnsupportedEnvironmentException;

it('allows supported environments', function (string $environment) {
    $this->setEnvironment($environment);

    expect(app(EnvironmentGuard::class)->ensureSupported())->toBe($environment);
})->with(['local', 'dev', 'development', 'staging']);

it('rejects every other environment', function (mixed $environment) {
    $this->setEnvironment($environment);

    expect(fn () => app(EnvironmentGuard::class)->ensureSupported())
        ->toThrow(UnsupportedEnvironmentException::class);
})->with([
    'production' => 'production',
    'prod' => 'prod',
    'testing' => 'testing',
    'qa' => 'qa',
    'uat' => 'uat',
    'live' => 'live',
    'unknown' => 'anything',
    'uppercase variant' => 'LOCAL',
    'padded variant' => ' local',
    'empty string' => '',
    'null' => null,
    'non-string' => [['local']],
]);

it('rejects when the environment binding is missing', function () {
    $this->app->offsetUnset('env');

    expect(fn () => app(EnvironmentGuard::class)->ensureSupported())
        ->toThrow(UnsupportedEnvironmentException::class);
});

it('keeps the allowlist out of configuration', function () {
    config(['realseed.allowed_environments' => ['production']]);
    $this->setEnvironment('production');

    expect(app(EnvironmentGuard::class)->isSupported())->toBeFalse();
});
