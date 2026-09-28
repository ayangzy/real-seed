<?php

use Ayangzy\RealSeed\Generation\RowState;
use Ayangzy\RealSeed\Generation\SeededRandom;
use Ayangzy\RealSeed\Generation\TemporalGenerator;
use Ayangzy\RealSeed\Generation\ValueGenerator;
use Ayangzy\RealSeed\Planning\FieldPlan;
use Ayangzy\RealSeed\Schema\ColumnSchema;
use Ayangzy\RealSeed\Semantics\ReferenceData;
use Ayangzy\RealSeed\Semantics\Semantic;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    Schema::create('currencies', function (Blueprint $table) {
        $table->id();
        $table->char('code', 3)->unique();
        $table->string('name');
        $table->string('symbol', 5);
        $table->timestamps();
    });

    Schema::create('countries', function (Blueprint $table) {
        $table->id();
        $table->char('iso2', 2)->unique();
        $table->char('iso3', 3)->unique();
        $table->string('name');
    });

    Schema::create('products', function (Blueprint $table) {
        $table->id();
        $table->foreignId('currency_id')->constrained();
        $table->foreignId('country_id')->constrained();
        $table->char('sku', 3)->unique();
        $table->string('name');
        $table->timestamps();
    });

    $this->setEnvironment('local');
});

it('fills currency and country tables with real, matching entries', function () {
    expect(Artisan::call('real:seed', ['--size' => 'small', '--seed' => 1, '--no-interaction' => true]))->toBe(0);

    foreach (DB::table('currencies')->get() as $currency) {
        expect(ReferenceData::CURRENCIES)->toHaveKey($currency->code)
            ->and(ReferenceData::CURRENCIES[$currency->code])->toBe([$currency->name, $currency->symbol]);
    }

    foreach (DB::table('countries')->get() as $country) {
        expect(ReferenceData::COUNTRIES)->toHaveKey($country->iso2)
            ->and(ReferenceData::COUNTRIES[$country->iso2])->toBe([$country->iso3, $country->name]);
    }
});

it('puts the locale\'s own currency and country first', function () {
    Artisan::call('real:seed', ['--size' => 'small', '--seed' => 1, '--locale' => 'ng', '--no-interaction' => true]);

    expect(DB::table('currencies')->orderBy('id')->value('code'))->toBe('NGN')
        ->and(DB::table('countries')->orderBy('id')->value('name'))->toBe('Nigeria');
});

it('never plans more catalog rows than real entries exist', function () {
    Artisan::call('real:seed', ['--count' => 5000, '--seed' => 1, '--no-interaction' => true]);

    expect(DB::table('currencies')->count())->toBeLessThanOrEqual(ReferenceData::size('currencies'))
        ->and(DB::table('countries')->count())->toBeLessThanOrEqual(ReferenceData::size('countries'));
});

it('generates compact unique codes for short code columns', function () {
    $values = new ValueGenerator(Faker\Factory::create(), new TemporalGenerator(0, 1000), fn () => 'x');
    $column = new ColumnSchema('sku', 'char', 'char(3)', false, null, false);

    $codes = array_map(
        fn (int $sequence) => $values->generate(new FieldPlan(Semantic::CODE), $column, new RowState('products', 0, $sequence), new SeededRandom(1)),
        range(1, 500),
    );

    expect($codes[0])->toBe('001')
        ->and($codes[34])->toBe('00Z')
        ->and($codes[35])->toBe('010')
        ->and(max(array_map('strlen', $codes)))->toBe(3)
        ->and(array_unique($codes))->toHaveCount(500);
});
