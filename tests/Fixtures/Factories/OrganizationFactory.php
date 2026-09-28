<?php

namespace AISeeder\Tests\Fixtures\Factories;

use AISeeder\Tests\Fixtures\Models\Organization;
use AISeeder\Tests\Fixtures\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class OrganizationFactory extends Factory
{
    protected $model = Organization::class;

    public function definition(): array
    {
        $name = fake()->randomElement(['Northwind', 'Contoso', 'Fabrikam']).' '.fake()->numberBetween(1, 999);

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            // The deferred side of the organizations <-> users cycle: must be overridden too.
            'owner_id' => User::factory(),
        ];
    }
}
