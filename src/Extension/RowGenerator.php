<?php

namespace AISeeder\Extension;

use AISeeder\Generation\SeededRandom;
use Carbon\CarbonImmutable;
use Faker\Generator as Faker;

/**
 * Generates attributes for a whole row of one table, like a factory definition.
 * Register under "row_generators" by table name. Returned values win over generated
 * ones, except keys and references, which AI Seeder always owns.
 */
interface RowGenerator
{
    /**
     * @param  array<string, mixed>  $references  The row's key and reference values.
     * @return array<string, mixed>
     */
    public function attributes(array $references, CarbonImmutable $time, Faker $faker, SeededRandom $random): array;
}
