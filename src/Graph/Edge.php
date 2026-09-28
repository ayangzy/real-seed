<?php

namespace Ayangzy\RealSeed\Graph;

/**
 * A child table referencing a parent table (child.columns -> parent.parentColumns).
 */
final readonly class Edge
{
    public const SOURCE_FOREIGN_KEY = 'foreign_key';

    public const SOURCE_RELATION = 'relation';

    /**
     * @param  list<string>  $columns
     * @param  list<string>  $parentColumns
     */
    public function __construct(
        public string $child,
        public array $columns,
        public string $parent,
        public array $parentColumns,
        public bool $nullable,
        public string $source,
    ) {
    }

    public function isSelfReferencing(): bool
    {
        return $this->child === $this->parent;
    }

    public function key(): string
    {
        return $this->child.'.'.implode(',', $this->columns);
    }

    public function describe(): string
    {
        return sprintf('%s.%s -> %s.%s', $this->child, implode(',', $this->columns), $this->parent, implode(',', $this->parentColumns));
    }
}
