<?php

use Ayangzy\RealSeed\Tests\Fixtures\Models\Organization;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * {name}_type + {name}_id columns look like Laravel's morphs(), but often aren't:
 * bank_accounts.account_type can be an enum of account kinds, account_id a plain link.
 */
beforeEach(function () {
    Schema::create('accounts', function (Blueprint $table) {
        $table->id();
        $table->string('name');
    });

    $this->setEnvironment('local');
    config(['realseed.model_paths' => []]);
});

function seedAccounts(): int
{
    return Artisan::call('real:seed', ['--size' => 'small', '--seed' => 1, '--no-interaction' => true]);
}

it('treats an enum _type column as data, not a polymorphic type', function () {
    Schema::create('bank_accounts', function (Blueprint $table) {
        $table->id();
        $table->enum('account_type', ['checking', 'savings', 'credit_card', 'cash', 'loan', 'other']);
        $table->unsignedBigInteger('account_id'); // no constraint: linked by naming convention
        $table->string('bank_name');
    });

    Schema::create('bank_transactions', function (Blueprint $table) {
        $table->id();
        $table->foreignId('bank_account_id')->constrained();
        $table->decimal('amount', 12, 2);
    });

    expect(seedAccounts())->toBe(0);
    expect(Artisan::output())->not->toContain('Skipping')->not->toContain("can't tell what");

    expect(DB::table('bank_accounts')->count())->toBeGreaterThan(0)
        ->and(DB::table('bank_transactions')->count())->toBeGreaterThan(0)
        ->and(DB::table('bank_accounts')->pluck('account_type')->unique()->diff(['checking', 'savings', 'credit_card', 'cash', 'loan', 'other']))->toBeEmpty()
        // account_id follows the convention to accounts.id.
        ->and(DB::table('bank_accounts')->whereNotIn('account_id', DB::table('accounts')->pluck('id'))->count())->toBe(0);
});

it('treats a pair whose _id is a foreign key as a plain reference', function () {
    Schema::create('bank_accounts', function (Blueprint $table) {
        $table->id();
        $table->string('account_type');
        $table->foreignId('account_id')->constrained();
    });

    expect(seedAccounts())->toBe(0)
        ->and(DB::table('bank_accounts')->count())->toBeGreaterThan(0)
        ->and(DB::table('bank_accounts')->where('account_type', 'like', '%\\\\%')->count())->toBe(0);
});

it('still recognises real polymorphic columns', function () {
    Schema::create('images', function (Blueprint $table) {
        $table->id();
        $table->morphs('imageable');
    });

    config(['realseed.morph_targets' => ['images.imageable' => [Organization::class]]]);
    Schema::create('organizations', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('slug')->unique();
        $table->unsignedBigInteger('owner_id')->nullable();
        $table->timestamps();
    });

    expect(seedAccounts())->toBe(0)
        ->and(DB::table('images')->pluck('imageable_type')->unique()->all())->toBe([Organization::class]);
});

it('ignores morph_targets for a pair that is not polymorphic, visibly', function () {
    Schema::create('bank_accounts', function (Blueprint $table) {
        $table->id();
        $table->enum('account_type', ['checking', 'savings']);
        $table->unsignedBigInteger('account_id');
    });

    config(['realseed.morph_targets' => ['bank_accounts.account' => [Organization::class]]]);

    expect(seedAccounts())->toBe(0);
    expect(Artisan::output())->toContain("Ignored morph_targets for [bank_accounts.account]: it isn't a polymorphic relation")
        ->and(DB::table('bank_accounts')->pluck('account_type')->unique()->diff(['checking', 'savings']))->toBeEmpty();
});
