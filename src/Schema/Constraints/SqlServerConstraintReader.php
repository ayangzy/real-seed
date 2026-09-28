<?php

namespace AISeeder\Schema\Constraints;

use Illuminate\Database\Connection;

/**
 * Best effort: SQL Server rewrites IN lists as ([col]='a' OR [col]='b').
 */
final class SqlServerConstraintReader implements ConstraintReader
{
    public function allowedValues(Connection $connection, string $table): array
    {
        $definitions = $connection->select(
            'select definition from sys.check_constraints where parent_object_id = object_id(?)',
            [$connection->getTablePrefix().$table],
        );

        $allowed = [];

        foreach ($definitions as $row) {
            preg_match_all("/\[(\w+)\]\s*=\s*N?'((?:[^']|'')*)'/i", $row->definition, $matches, PREG_SET_ORDER);

            $columns = array_unique(array_column($matches, 1));

            // Only a disjunction over one column is a value list.
            if (count($columns) === 1 && ! preg_match('/\bAND\b/i', $row->definition)) {
                $allowed[$columns[0]] = array_map(fn ($m) => str_replace("''", "'", $m[2]), $matches);
            }
        }

        return $allowed;
    }
}
