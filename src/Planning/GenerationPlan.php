<?php

namespace AISeeder\Planning;

use Carbon\CarbonImmutable;

/**
 * Everything needed to generate a dataset. Given the same plan and database schema,
 * generation is fully deterministic.
 */
final readonly class GenerationPlan
{
    /**
     * @param  array<string, TablePlan>  $tables  Keyed by table name; count 0 means "use existing rows only".
     */
    public function __construct(
        public int $seed,
        public string $locale,
        public CarbonImmutable $start,
        public CarbonImmutable $end,
        public array $tables,
        public ?string $scenario = null,
    ) {
    }

    public function table(string $table): ?TablePlan
    {
        return $this->tables[$table] ?? null;
    }

    public function count(string $table): int
    {
        return $this->tables[$table]->count ?? 0;
    }

    public function totalRows(): int
    {
        return array_sum(array_map(fn (TablePlan $plan) => $plan->count, $this->tables));
    }

    /**
     * @return list<string>
     */
    public function generatedTables(): array
    {
        return array_keys(array_filter($this->tables, fn (TablePlan $plan) => $plan->count > 0));
    }

    public function withTables(array $tables): self
    {
        return new self($this->seed, $this->locale, $this->start, $this->end, $tables, $this->scenario);
    }

    public function withSeed(int $seed): self
    {
        return new self($seed, $this->locale, $this->start, $this->end, $this->tables, $this->scenario);
    }

    public function toArray(): array
    {
        return [
            'seed' => $this->seed,
            'locale' => $this->locale,
            'scenario' => $this->scenario,
            'timeline' => [
                'start' => $this->start->toIso8601String(),
                'end' => $this->end->toIso8601String(),
            ],
            'tables' => array_map(fn (TablePlan $plan) => $plan->toArray(), $this->tables),
        ];
    }

    public static function fromArray(array $data): self
    {
        $tables = [];

        foreach ((array) ($data['tables'] ?? []) as $table => $plan) {
            $tables[$table] = TablePlan::fromArray($table, (array) $plan);
        }

        return new self(
            seed: (int) $data['seed'],
            locale: (string) $data['locale'],
            start: CarbonImmutable::parse($data['timeline']['start']),
            end: CarbonImmutable::parse($data['timeline']['end']),
            tables: $tables,
            scenario: $data['scenario'] ?? null,
        );
    }
}
