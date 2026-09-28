<?php

use Ayangzy\RealSeed\Tests\Fixtures\Models\Organization;
use Ayangzy\RealSeed\Tests\Fixtures\SaasSchema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    SaasSchema::create();

    // A polymorphic owner that no model declares a morphMany/morphOne for.
    Schema::create('bank_accounts', function (Blueprint $table) {
        $table->id();
        $table->morphs('account');
        $table->string('bank_name');
        $table->timestamps();
    });

    Schema::create('reconciliations', function (Blueprint $table) {
        $table->id();
        $table->foreignId('bank_account_id')->constrained();
        $table->decimal('amount', 12, 2);
        $table->timestamps();
    });

    $this->setEnvironment('local');
});

function seedBanks(): int
{
    return Artisan::call('real:seed', ['--size' => 'small', '--seed' => 1, '--no-interaction' => true]);
}

it('skips unresolvable polymorphic tables and their dependents before writing, with guidance', function () {
    expect(seedBanks())->toBe(0);

    expect(Artisan::output())
        ->toContain("Skipping [bank_accounts]: RealSeed can't tell what [account] can point to")
        ->toContain("'morph_targets' => ['bank_accounts.account' => [YourModel::class]]")
        ->toContain('Skipping [reconciliations]: it needs rows in [bank_accounts], which is empty and not being generated.')
        ->and(DB::table('organizations')->count())->toBeGreaterThan(0)
        ->and(DB::table('reconciliations')->count())->toBe(0);
});

it('uses polymorphic targets from config', function () {
    config(['realseed.morph_targets' => ['bank_accounts.account' => [Organization::class]]]);

    expect(seedBanks())->toBe(0)
        ->and(DB::table('bank_accounts')->count())->toBeGreaterThan(0)
        ->and(DB::table('reconciliations')->count())->toBeGreaterThan(0)
        ->and(DB::table('bank_accounts')->pluck('account_type')->unique()->all())->toBe([Organization::class])
        ->and(DB::table('bank_accounts')->whereNotIn('account_id', DB::table('organizations')->pluck('id'))->count())->toBe(0);
});

it('learns polymorphic targets from rows already in the table', function () {
    $organization = DB::table('organizations')->insertGetId(['name' => 'Acme', 'slug' => 'acme', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('bank_accounts')->insert(['account_type' => Organization::class, 'account_id' => $organization, 'bank_name' => 'First Bank']);

    expect(seedBanks())->toBe(0)
        ->and(DB::table('bank_accounts')->count())->toBeGreaterThan(1)
        ->and(DB::table('reconciliations')->count())->toBeGreaterThan(0);
});

it('skips chains of dependents at any depth before writing', function () {
    Schema::create('bank_transactions', function (Blueprint $table) {
        $table->id();
        $table->foreignId('bank_account_id')->constrained();
        $table->decimal('amount', 12, 2);
    });

    Schema::create('reconciliation_items', function (Blueprint $table) {
        $table->id();
        $table->foreignId('reconciliation_id')->constrained();
        $table->foreignId('bank_transaction_id')->constrained();
    });

    expect(seedBanks())->toBe(0);

    expect(Artisan::output())
        ->toContain('Skipping [bank_transactions]: it needs rows in [bank_accounts]')
        ->toContain('Skipping [reconciliation_items]: it needs rows in [bank_transactions, reconciliations], which are empty')
        ->not->toContain('Generation failed')
        ->and(DB::table('organizations')->count())->toBeGreaterThan(0);
});
