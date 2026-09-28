<?php

namespace AISeeder\Tests\Fixtures\Factories;

use AISeeder\Tests\Fixtures\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProjectFactory extends Factory
{
    protected $model = Project::class;

    public function definition(): array
    {
        // A side effect AI Seeder must detect, roll back, and refuse to repeat.
        DB::table('tags')->insert(['name' => 'side-effect-'.fake()->uuid()]);

        return ['name' => 'Unsafe project'];
    }
}
