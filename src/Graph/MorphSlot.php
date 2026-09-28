<?php

namespace AISeeder\Graph;

/**
 * A polymorphic reference: a {name}_type / {name}_id column pair.
 */
final readonly class MorphSlot
{
    /**
     * @param  array<string, string>  $targets  Morph class value => target table, discovered from models.
     */
    public function __construct(
        public string $table,
        public string $name,
        public string $typeColumn,
        public string $idColumn,
        public bool $nullable,
        public array $targets = [],
    ) {
    }

    public function withTarget(string $morphClass, string $table): self
    {
        $targets = $this->targets + [$morphClass => $table];
        ksort($targets);

        return new self($this->table, $this->name, $this->typeColumn, $this->idColumn, $this->nullable, $targets);
    }
}
