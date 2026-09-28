<?php

namespace Ayangzy\RealSeed\Planning;

/**
 * Converts the friendly "overrides" map in config/realseed.php into the suggestion
 * format every planner input uses, so configuration is validated like everything else:
 *
 *     'users' => [
 *         'count' => 200,
 *         'states' => ['admin' => 1, 'default' => 20],
 *         'fields' => [
 *             'status' => ['weights' => ['active' => 90, 'suspended' => 10]],
 *             'bio' => ['samples' => ['Coffee first.', '...']],
 *             'manager_id' => ['null_rate' => 0.5, 'selection' => 'uniform'],
 *             'cancelled_at' => ['present_when' => ['status' => ['cancelled']]],
 *         ],
 *     ],
 */
final class ConfigOverrides
{
    public static function toSuggestions(array $overrides): array
    {
        $tables = [];

        foreach ($overrides as $table => $override) {
            if (! is_string($table) || ! is_array($override)) {
                continue;
            }

            $fields = [];

            foreach ((array) ($override['fields'] ?? []) as $column => $field) {
                if (! is_string($column) || ! is_array($field)) {
                    continue;
                }

                $condition = $field['present_when'] ?? null;

                $fields[] = [
                    'column' => $column,
                    'semantic' => $field['semantic'] ?? null,
                    'samples' => $field['samples'] ?? null,
                    'weights' => self::pairs($field['weights'] ?? null, 'value'),
                    'null_rate' => $field['null_rate'] ?? null,
                    'true_rate' => $field['true_rate'] ?? null,
                    'min' => $field['min'] ?? null,
                    'max' => $field['max'] ?? null,
                    'present_when' => is_array($condition) && $condition !== []
                        ? ['column' => array_key_first($condition), 'values' => array_values((array) reset($condition))]
                        : null,
                    'selection' => $field['selection'] ?? null,
                    'scope' => $field['scope'] ?? null,
                ];
            }

            $tables[] = [
                'table' => $table,
                'count' => $override['count'] ?? null,
                'states' => self::pairs($override['states'] ?? null, 'name'),
                'fields' => $fields,
            ];
        }

        return ['tables' => $tables];
    }

    /**
     * ['active' => 90] -> [['value' => 'active', 'weight' => 90]]
     */
    private static function pairs(mixed $weights, string $key): ?array
    {
        if (! is_array($weights)) {
            return null;
        }

        return array_map(fn ($name, $weight) => [$key => (string) $name, 'weight' => $weight], array_keys($weights), $weights);
    }
}
