<?php

namespace Ayangzy\RealSeed\Schema\Constraints;

use Illuminate\Database\Connection;

final class SqliteConstraintReader implements ConstraintReader
{
    public function allowedValues(Connection $connection, string $table): array
    {
        $sql = $connection->scalar(
            "select sql from sqlite_master where type = 'table' and name = ?",
            [$connection->getTablePrefix().$table],
        );

        if (! is_string($sql)) {
            return [];
        }

        // Matches: check ("status" in ('draft', 'published'))
        preg_match_all(
            '/check\s*\(\s*["`\[]?(\w+)["`\]]?\s+in\s*\(((?:\s*\'(?:[^\']|\'\')*\'\s*,?)+)\)\s*\)/i',
            $sql,
            $matches,
            PREG_SET_ORDER,
        );

        $allowed = [];

        foreach ($matches as [, $column, $list]) {
            $allowed[$column] = QuotedList::parse($list);
        }

        return $allowed;
    }
}
