<?php

use AISeeder\Environment\ConnectionSafetyCheck;
use AISeeder\Environment\TargetDatabase;

function target(string $database, ?string $host = '127.0.0.1', string $driver = 'mysql'): TargetDatabase
{
    return new TargetDatabase('mysql', $driver, $database, $host);
}

it('blocks production-looking database names and hosts', function (TargetDatabase $target) {
    expect((new ConnectionSafetyCheck)->assess($target, 'staging')['status'])
        ->toBe(ConnectionSafetyCheck::BLOCKED);
})->with([
    'db suffix' => fn () => target('myapp_production'),
    'db prod token' => fn () => target('prod'),
    'db live token' => fn () => target('shop-live'),
    'host token' => fn () => target('myapp', 'db.prod.internal'),
    'sqlite file' => fn () => target('/var/data/production.sqlite', null, 'sqlite'),
]);

it('does not block names that merely contain a production token as a substring', function (string $database) {
    expect((new ConnectionSafetyCheck)->assess(target($database), 'local')['status'])
        ->toBe(ConnectionSafetyCheck::OK);
})->with(['products', 'productivity_app', 'delivery', 'olive_shop']);

it('requires typed confirmation when local points at a remote host', function () {
    expect((new ConnectionSafetyCheck)->assess(target('myapp', 'db.example.com'), 'local')['status'])
        ->toBe(ConnectionSafetyCheck::REQUIRES_TYPED_CONFIRMATION);
});

it('treats common local hosts as local', function (string $host) {
    expect((new ConnectionSafetyCheck)->assess(target('myapp', $host), 'local')['status'])
        ->toBe(ConnectionSafetyCheck::OK);
})->with(['localhost', '127.0.0.1', '::1', 'mysql', 'pgsql', 'host.docker.internal', 'db.test']);

it('allows remote hosts for staging', function () {
    expect((new ConnectionSafetyCheck)->assess(target('myapp_staging', 'staging-db.internal'), 'staging')['status'])
        ->toBe(ConnectionSafetyCheck::OK);
});
