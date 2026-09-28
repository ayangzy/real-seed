<?php

namespace AISeeder\Graph;

final readonly class DependencyOrder
{
    /**
     * @param  list<string>  $tables  Parents before children.
     * @param  list<Edge>  $deferred  Nullable edges broken to resolve cycles; inserted as null, then back-filled.
     */
    public function __construct(
        public array $tables,
        public array $deferred,
    ) {
    }

    public function hasCycles(): bool
    {
        return $this->deferred !== [];
    }

    public function isDeferred(Edge $edge): bool
    {
        foreach ($this->deferred as $deferred) {
            if ($deferred->key() === $edge->key()) {
                return true;
            }
        }

        return false;
    }
}
