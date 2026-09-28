<?php

namespace AISeeder\Schema\Constraints;

use Illuminate\Database\Connection;

final class PostgresConstraintReader implements ConstraintReader
{
    public function allowedValues(Connection $connection, string $table): array
    {
        $definitions = $connection->select(
            "select pg_get_constraintdef(c.oid) as definition
             from pg_constraint c
             where c.contype = 'c' and c.conrelid = to_regclass(?)",
            [$connection->getTablePrefix().$table],
        );

        $allowed = [];

        foreach ($definitions as $row) {
            // Matches: CHECK (((status)::text = ANY ((ARRAY['a'::character varying, 'b'::character varying])::text[])))
            if (preg_match('/\(+"?(\w+)"?\)?(?:::[\w ]+)?\s*=\s*ANY\s*\(+ARRAY\[(.*?)\]/i', $row->definition, $matches)) {
                $allowed[$matches[1]] = QuotedList::parse($matches[2]);
            }
        }

        return $allowed;
    }
}
