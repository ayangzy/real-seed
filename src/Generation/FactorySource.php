<?php

namespace Ayangzy\RealSeed\Generation;

use Ayangzy\RealSeed\Analysis\FactoryInfo;
use Ayangzy\RealSeed\Validation\GenerationException;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Events\QueryExecuted;
use Throwable;

/**
 * Supplies attribute values from a model's Laravel factory, safely.
 *
 * Factory definitions are arbitrary code. RealSeed passes its own keys for every
 * reference, so nested factories (e.g. 'team_id' => Team::factory()) are never
 * expanded, and it only calls raw(), which runs no afterMaking/afterCreating hooks.
 * The first row is probed inside a savepoint that is always rolled back: a factory
 * that writes anyway is marked unsafe and the engine generates that table instead.
 * Any write after the probe fails the run, which rolls the whole transaction back.
 */
final class FactorySource
{
    private const WRITE = '/^\s*(insert|update|delete|replace|upsert|merge|truncate|drop|alter|create)\b/i';

    private bool $watching = false;

    private ?string $write = null;

    private ?bool $safe = null;

    private ?string $unsafeReason = null;

    public function __construct(
        private readonly Connection $db,
        public readonly FactoryInfo $info,
        public readonly string $table,
        /** @var list<string> */
        private readonly array $columns,
    ) {
        $db->listen(function (QueryExecuted $query) {
            if ($this->watching && $query->connectionName === $this->db->getName() && preg_match(self::WRITE, $query->sql)) {
                $this->write ??= $query->sql;
            }
        });
    }

    /**
     * Factory attributes for one row, limited to real columns of the table.
     *
     * @param  array<string, mixed>  $overrides  Keys and references chosen by RealSeed.
     * @return array<string, mixed>|null Null when the factory is unsafe to use.
     */
    public function attributes(array $overrides, ?string $state): ?array
    {
        if ($this->safe === false) {
            return null;
        }

        if ($this->safe === null) {
            return $this->probe($overrides, $state);
        }

        $this->write = null;
        $this->watching = true;

        try {
            $attributes = $this->raw($overrides, $state);
        } finally {
            $this->watching = false;
        }

        if ($this->write !== null) {
            throw new GenerationException($this->table, null, "the {$this->info->factory} definition wrote to the database ({$this->write})");
        }

        return $attributes;
    }

    public function unsafeReason(): ?string
    {
        return $this->unsafeReason;
    }

    private function probe(array $overrides, ?string $state): ?array
    {
        $this->write = null;
        $this->watching = true;
        $this->db->beginTransaction(); // a savepoint inside the run's transaction

        try {
            $attributes = $this->raw($overrides, $state);
        } catch (Throwable $e) {
            $attributes = null;
            $this->unsafeReason = 'it failed: '.$e->getMessage();
        } finally {
            $this->watching = false;
            $this->db->rollBack();
        }

        if ($this->write !== null) {
            $attributes = null;
            $this->unsafeReason = "its definition writes to the database ({$this->write})";
        }

        $this->safe = $attributes !== null;

        return $attributes;
    }

    private function raw(array $overrides, ?string $state): array
    {
        /** @var Factory $factory */
        $factory = $this->info->model::factory();

        if ($state !== null) {
            $factory = $factory->{$state}();
        }

        $attributes = $factory->raw($overrides);

        return array_intersect_key($attributes, array_flip($this->columns));
    }
}
