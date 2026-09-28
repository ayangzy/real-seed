<?php

namespace AISeeder\Extension;

/**
 * Chooses the parent a reference points to. Register under "reference_pickers" by
 * "table.column". Scope-consistent candidates are computed first; the picker chooses
 * among them.
 */
interface ReferencePicker
{
    /**
     * @return int|string|null  One of $context->candidates, or null for "no parent" (nullable
     *                          columns) / "use the default choice" (required columns).
     */
    public function pick(ReferenceContext $context): int|string|null;
}
