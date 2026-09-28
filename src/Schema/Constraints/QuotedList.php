<?php

namespace Ayangzy\RealSeed\Schema\Constraints;

final class QuotedList
{
    /**
     * Extracts the values from a SQL list such as 'a', 'b''s'::text, N'c'.
     *
     * @return list<string>
     */
    public static function parse(string $list): array
    {
        preg_match_all("/N?'((?:[^']|'')*)'/", $list, $matches);

        return array_map(fn (string $value) => str_replace("''", "'", $value), $matches[1]);
    }
}
