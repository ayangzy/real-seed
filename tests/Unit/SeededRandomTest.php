<?php

use AISeeder\Generation\SeededRandom;

it('produces the same sequence for the same seed', function () {
    $a = new SeededRandom(7);
    $b = new SeededRandom(7);

    expect(array_map(fn () => $a->int(0, 1000), range(1, 20)))
        ->toBe(array_map(fn () => $b->int(0, 1000), range(1, 20)));
});

it('derives independent streams per label', function () {
    expect(SeededRandom::deriveSeed(1, 'users'))->toBe(SeededRandom::deriveSeed(1, 'users'))
        ->not->toBe(SeededRandom::deriveSeed(1, 'tasks'))
        ->not->toBe(SeededRandom::deriveSeed(2, 'users'));
});

it('is unaffected by the global mt_rand state', function () {
    $a = new SeededRandom(3);
    $first = $a->int(0, PHP_INT_MAX);

    mt_srand(999);
    mt_rand();

    expect((new SeededRandom(3))->int(0, PHP_INT_MAX))->toBe($first);
});

it('generates valid v4 UUIDs and time-ordered ULIDs', function () {
    $random = new SeededRandom(1);

    expect($random->uuid())->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/');

    $earlier = $random->ulid(1_700_000_000_000);
    $later = $random->ulid(1_800_000_000_000);

    expect($earlier)->toHaveLength(26)->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/')
        ->and(strcmp(substr($earlier, 0, 10), substr($later, 0, 10)))->toBeLessThan(0);
});

it('never picks zero-weight values and skews towards low indexes', function () {
    $random = new SeededRandom(5);
    $picks = array_map(fn () => $random->weighted(['a' => 1, 'b' => 0, 'c' => 3]), range(1, 500));
    $indexes = array_map(fn () => $random->skewedIndex(10), range(1, 2000));

    expect($picks)->not->toContain('b')
        ->and(min($indexes))->toBe(0)
        ->and(max($indexes))->toBeLessThan(10)
        ->and(count(array_filter($indexes, fn ($i) => $i < 5)))->toBeGreaterThan(count(array_filter($indexes, fn ($i) => $i >= 5)) * 2);
});
