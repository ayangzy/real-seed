<?php

namespace Ayangzy\RealSeed\Tests\Fixtures\Factories;

use Ayangzy\RealSeed\Tests\Fixtures\Models\Organization;
use Ayangzy\RealSeed\Tests\Fixtures\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        $first = fake()->randomElement(['Ada', 'Grace', 'Katherine', 'Hedy']);

        return [
            // Nested factory: RealSeed must override it instead of letting it create an organization.
            'organization_id' => Organization::factory(),
            'first_name' => $first,
            'last_name' => 'Factoryson',
            'email' => strtolower($first).'.'.fake()->numberBetween(1, 99999).'@example.test',
            'password' => 'hashed-by-factory',
            'status' => 'invited',
        ];
    }

    public function suspended(): static
    {
        return $this->state(['status' => 'suspended']);
    }
}
