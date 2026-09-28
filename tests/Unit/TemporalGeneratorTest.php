<?php

use Ayangzy\RealSeed\Generation\SeededRandom;
use Ayangzy\RealSeed\Generation\TemporalGenerator;

beforeEach(function () {
    $this->time = new TemporalGenerator(1_000_000, 2_000_000);
    $this->random = new SeededRandom(11);
});

it('keeps root rows inside the timeline', function () {
    foreach (range(1, 200) as $_) {
        expect($this->time->rowTime(null, $this->random))->toBeGreaterThanOrEqual(1_000_000)->toBeLessThanOrEqual(2_000_000);
    }
});

it('places children after their parents', function () {
    foreach (range(1, 200) as $_) {
        $parent = $this->time->rowTime(null, $this->random);

        expect($this->time->rowTime($parent, $this->random))->toBeGreaterThanOrEqual($parent)->toBeLessThanOrEqual(2_000_000);
    }
});

it('orders created, events, and updated timestamps', function () {
    foreach (range(1, 200) as $_) {
        $created = $this->time->rowTime(null, $this->random);
        $event = $this->time->past($created, $this->random);

        expect($event)->toBeGreaterThanOrEqual($created)->toBeLessThanOrEqual(2_000_000)
            ->and($this->time->updated($created, $event, $this->random))->toBeGreaterThanOrEqual($event)
            ->and($this->time->spanEnd($event, $this->random))->toBeGreaterThan($event);
    }
});
