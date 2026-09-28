<?php

namespace AISeeder\Tests\Fixtures\Extensions;

use AISeeder\Extension\RowGenerator;
use AISeeder\Generation\SeededRandom;
use Carbon\CarbonImmutable;
use Faker\Generator as Faker;

class TaskRowGenerator implements RowGenerator
{
    public function attributes(array $references, CarbonImmutable $time, Faker $faker, SeededRandom $random): array
    {
        return [
            'title' => 'Task for project '.$references['project_id'],
            'project_id' => 999999, // structural: must be ignored
        ];
    }
}
