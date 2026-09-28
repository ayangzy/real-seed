<?php

namespace Ayangzy\RealSeed\Planning;

use Ayangzy\RealSeed\AI\ApplicationContext;
use Ayangzy\RealSeed\Analysis\ProjectAnalysis;
use Ayangzy\RealSeed\Graph\DependencyResolver;
use Ayangzy\RealSeed\Schema\ColumnSchema;
use Ayangzy\RealSeed\Semantics\Semantic;

/**
 * Merges AI suggestions onto the heuristic baseline plan.
 *
 * AI output is untrusted input: anything that doesn't match the discovered schema is
 * dropped, numbers are clamped, and structural fields (keys, references, polymorphic
 * columns) can't be changed at all. The result can only describe values for existing
 * columns, so no AI output can reach the database as SQL or code.
 */
final class PlanValidator
{
    private const MAX_SAMPLES = 60;

    private const MAX_SAMPLE_LENGTH = 500;

    private const TEXT = [
        Semantic::FIRST_NAME, Semantic::LAST_NAME, Semantic::FULL_NAME, Semantic::EMAIL, Semantic::USERNAME, Semantic::URL,
        Semantic::DOMAIN, Semantic::IP, Semantic::PHONE, Semantic::STREET, Semantic::CITY, Semantic::STATE, Semantic::POSTCODE,
        Semantic::COUNTRY, Semantic::COUNTRY_CODE, Semantic::COMPANY, Semantic::JOB_TITLE, Semantic::TITLE, Semantic::NAME,
        Semantic::SENTENCE, Semantic::PARAGRAPH, Semantic::SLUG, Semantic::CODE, Semantic::WORD, Semantic::COLOR,
        Semantic::CURRENCY, Semantic::CURRENCY_NAME, Semantic::CURRENCY_SYMBOL, Semantic::LOCALE, Semantic::TIMEZONE, Semantic::IMAGE_URL,
    ];

    private const SAMPLEABLE = [
        Semantic::FIRST_NAME, Semantic::LAST_NAME, Semantic::FULL_NAME, Semantic::COMPANY, Semantic::JOB_TITLE, Semantic::TITLE,
        Semantic::NAME, Semantic::SENTENCE, Semantic::PARAGRAPH, Semantic::WORD, Semantic::CITY, Semantic::STATE,
        Semantic::STREET, Semantic::COUNTRY,
    ];

    private const NUMERIC = [
        Semantic::MONEY, Semantic::QUANTITY, Semantic::INTEGER, Semantic::DECIMAL, Semantic::PERCENTAGE, Semantic::RATING,
        Semantic::YEAR,
    ];

    private const TEMPORAL = [Semantic::CREATED_AT, Semantic::UPDATED_AT, Semantic::DELETED_AT, Semantic::PAST, Semantic::FUTURE, Semantic::BIRTH_DATE];

    /** @var list<string> */
    private array $warnings = [];

    private bool $trusted = false;

    public function __construct(
        private readonly ProjectAnalysis $analysis,
        private readonly int $maxRows = 250000,
    ) {
    }

    /**
     * @param  array<string, mixed>  $suggestions  Decoded AI output (see PlanPrompt::schema()).
     * @param  bool  $trusted  Developer-provided (config, scenario providers, analyzers): counts are
     *                         honoured as given instead of being clamped. Everything is still validated.
     */
    public function merge(GenerationPlan $base, array $suggestions, PlanOptions $options, bool $trusted = false): GenerationPlan
    {
        $this->warnings = [];
        $this->trusted = $trusted;
        $tables = $base->tables;

        foreach ((array) ($suggestions['tables'] ?? []) as $suggestion) {
            if (! is_array($suggestion) || ! is_string($suggestion['table'] ?? null)) {
                continue;
            }

            $name = $suggestion['table'];

            if (! isset($tables[$name])) {
                $this->warnings[] = "Ignored unknown table [{$name}].";

                continue;
            }

            $tables[$name] = $this->mergeTable($tables[$name], $suggestion);
        }

        $tables = $this->enforceCounts($tables, $options);

        $start = $base->start;
        $months = $suggestions['timeline_months'] ?? null;

        if (is_int($months) || (is_numeric($months) && (int) $months == $months)) {
            $start = $base->end->subMonths(max(1, min(60, (int) $months)));
        }

        return new GenerationPlan($base->seed, $base->locale, $start, $base->end, $tables, $base->scenario);
    }

    /**
     * @return list<string>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    private function mergeTable(TablePlan $plan, array $suggestion): TablePlan
    {
        $count = $plan->count;

        // Tables outside this run's selection stay at zero whatever the AI says.
        if ($count > 0 && is_numeric($suggestion['count'] ?? null)) {
            $count = $this->trusted
                ? max(1, (int) $suggestion['count'])
                : max(1, min((int) $suggestion['count'], max(10, $plan->count * 10)));
        }

        $fields = $plan->fields;

        foreach ((array) ($suggestion['fields'] ?? []) as $field) {
            if (! is_array($field) || ! is_string($field['column'] ?? null)) {
                continue;
            }

            $column = $field['column'];

            if (! isset($fields[$column]) || ($schema = $this->analysis->schema->table($plan->table)?->column($column)) === null) {
                $this->warnings[] = "Ignored unknown column [{$plan->table}.{$column}].";

                continue;
            }

            $fields[$column] = $this->mergeField($plan, $fields[$column], $schema, $field, $count);
        }

        $options = $plan->options;

        if (is_array($suggestion['states'] ?? null) && ($states = $this->factoryStates($plan->table, $suggestion['states'])) !== null) {
            $options['factory_states'] = $states;
        }

        return new TablePlan($plan->table, $count, $fields, $options);
    }

    private function mergeField(TablePlan $table, FieldPlan $base, ColumnSchema $column, array $suggestion, int $count): FieldPlan
    {
        $where = "{$table->table}.{$column->name}";

        if (in_array($base->semantic, [Semantic::KEY, Semantic::MORPH_TYPE, Semantic::MORPH_ID], true)) {
            return $base; // Structure comes from the schema.
        }

        if ($base->semantic === Semantic::REFERENCE) {
            // Which parent is referenced is structural; how parents are chosen can be tuned.
            return $base->with($this->referenceRules($table->table, $column, $suggestion));
        }

        $semantic = $base->semantic;
        $options = $base->options;
        $proposed = $suggestion['semantic'] ?? null;

        if (is_string($proposed) && $proposed !== $semantic) {
            if (in_array($proposed, ApplicationContext::assignableSemantics(), true) && $this->compatible($proposed, $column)) {
                $semantic = $proposed;
                $options = array_intersect_key($options, ['null_rate' => true]);
            } else {
                $this->warnings[] = "Ignored semantic [{$proposed}] for [{$where}]: it doesn't fit a {$column->type} column.";
            }
        }

        if (is_array($suggestion['weights'] ?? null) && ($weights = $this->weights($table->table, $column, $suggestion['weights'])) !== null) {
            $options['weights'] = $weights;
        } elseif ($semantic === Semantic::ENUM && ! isset($options['weights']) && $column->allowedValues === null) {
            $this->warnings[] = "Ignored semantic [enum] for [{$where}]: no valid values were given.";
            $semantic = $base->semantic;
            $options = $base->options;
        }

        if (is_array($suggestion['samples'] ?? null) && in_array($semantic, self::SAMPLEABLE, true)) {
            $samples = $this->samples($suggestion['samples'], $column);
            $unique = $this->analysis->schema->table($table->table)->isUnique($column->name);

            if ($samples !== [] && (! $unique || count($samples) >= $count)) {
                $options['samples'] = $samples;
            }
        }

        if ($column->nullable && is_numeric($suggestion['null_rate'] ?? null)) {
            $options['null_rate'] = $this->rate($suggestion['null_rate']);
        }

        if ($semantic === Semantic::BOOLEAN && is_numeric($suggestion['true_rate'] ?? null)) {
            $options['true_rate'] = $this->rate($suggestion['true_rate']);
        }

        if (in_array($semantic, self::NUMERIC, true)) {
            $min = $this->finite($suggestion['min'] ?? null);
            $max = $this->finite($suggestion['max'] ?? null);

            if ($min !== null && $max !== null && $min > $max) {
                [$min, $max] = [$max, $min];
            }

            if ($min !== null) {
                $options['min'] = $min;
            }

            if ($max !== null) {
                $options['max'] = $max;
            }
        }

        if ($column->nullable && is_array($suggestion['present_when'] ?? null)
            && ($condition = $this->condition($table, $suggestion['present_when'])) !== null) {
            $options['present_when'] = $condition;
        }

        return new FieldPlan($semantic, $options);
    }

    private function referenceRules(string $table, ColumnSchema $column, array $suggestion): array
    {
        $rules = [];

        if ($column->nullable && is_numeric($suggestion['null_rate'] ?? null)) {
            $rules['null_rate'] = $this->rate($suggestion['null_rate']);
        }

        if (in_array($suggestion['selection'] ?? null, ['skewed', 'uniform'], true)) {
            $rules['selection'] = $suggestion['selection'];
        }

        $scope = $suggestion['scope'] ?? null;

        if ($scope === false || $scope === 'none' || (is_string($scope) && $this->analysis->schema->has($scope))) {
            $rules['scope'] = $scope;
        } elseif ($scope !== null) {
            $this->warnings[] = "Ignored scope for [{$table}.{$column->name}]: unknown table.";
        }

        return $rules;
    }

    /**
     * @return array<string, float>|null
     */
    private function factoryStates(string $table, array $items): ?array
    {
        $known = [...($this->analysis->factory($table)?->states ?? []), 'default'];
        $weights = [];

        foreach ($items as $item) {
            $name = is_array($item) ? ($item['name'] ?? null) : null;
            $weight = is_array($item) ? $this->finite($item['weight'] ?? null) : null;

            if (! is_string($name) || ! in_array($name, $known, true) || $weight === null || $weight < 0) {
                $this->warnings[] = 'Ignored factory state ['.(is_scalar($name) ? $name : '?')."] for [{$table}].";

                continue;
            }

            $weights[$name] = $weight;
        }

        return count($known) > 1 && array_sum($weights) > 0 ? $weights : null;
    }

    private function compatible(string $semantic, ColumnSchema $column): bool
    {
        $family = $column->family();

        return match (true) {
            $semantic === Semantic::NULL => $column->nullable,
            $semantic === Semantic::ENUM => in_array($family, ['string', 'text', 'integer'], true),
            $semantic === Semantic::BOOLEAN => in_array($family, ['boolean', 'integer'], true),
            in_array($semantic, [Semantic::LATITUDE, Semantic::LONGITUDE], true) => in_array($family, ['decimal', 'string'], true),
            in_array($semantic, self::NUMERIC, true) => in_array($family, ['integer', 'decimal'], true),
            in_array($semantic, self::TEMPORAL, true) => in_array($family, ['date', 'datetime'], true),
            $semantic === Semantic::TIME => $family === 'time',
            $semantic === Semantic::JSON => in_array($family, ['json', 'text'], true),
            in_array($semantic, [Semantic::UUID, Semantic::ULID], true) => in_array($family, ['uuid', 'string'], true),
            in_array($semantic, [Semantic::PASSWORD, Semantic::TOKEN], true) => in_array($family, ['string', 'text'], true),
            in_array($semantic, self::TEXT, true) => in_array($family, ['string', 'text'], true),
            default => false,
        };
    }

    /**
     * @return array<string, float>|null
     */
    private function weights(string $table, ColumnSchema $column, array $items): ?array
    {
        $allowed = $this->analysis->enum($table, $column->name)?->values ?? $column->allowedValues;
        $allowed = $allowed === null ? null : array_map('strval', $allowed);
        $weights = [];

        foreach ($items as $item) {
            $value = is_array($item) ? ($item['value'] ?? null) : null;
            $weight = is_array($item) ? $this->finite($item['weight'] ?? null) : null;

            if (! is_scalar($value) || $weight === null || $weight < 0) {
                continue;
            }

            $value = trim((string) $value);

            if ($value === '' || ($allowed !== null && ! in_array($value, $allowed, true))
                || ($allowed === null && ($column->maxLength() !== null && mb_strlen($value) > $column->maxLength()))
                || ($column->family() === 'integer' && ! ctype_digit(ltrim($value, '-')))) {
                $this->warnings[] = "Ignored value [{$value}] for [{$table}.{$column->name}].";

                continue;
            }

            $weights[$value] = $weight;
        }

        return array_sum($weights) > 0 ? $weights : null;
    }

    /**
     * @return list<string>
     */
    private function samples(array $samples, ColumnSchema $column): array
    {
        $limit = min(self::MAX_SAMPLE_LENGTH, $column->maxLength() ?? self::MAX_SAMPLE_LENGTH);
        $clean = [];

        foreach (array_slice($samples, 0, self::MAX_SAMPLES * 2) as $sample) {
            if (! is_string($sample)) {
                continue;
            }

            $sample = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $sample) ?? '');

            if ($sample !== '' && mb_strlen($sample) <= $limit) {
                $clean[$sample] = true;
            }
        }

        return array_slice(array_keys($clean), 0, self::MAX_SAMPLES);
    }

    /**
     * @return array<string, list<string>>|null
     */
    private function condition(TablePlan $table, array $condition): ?array
    {
        $column = $condition['column'] ?? null;
        $values = $condition['values'] ?? null;

        if (! is_string($column) || ! is_array($values) || ($field = $table->field($column)) === null) {
            return null;
        }

        $allowed = $this->analysis->enum($table->table, $column)?->values
            ?? $this->analysis->schema->table($table->table)->column($column)?->allowedValues
            ?? array_keys((array) $field->option('weights', []));

        $allowed = array_map('strval', $allowed);
        $values = array_values(array_intersect(array_map(fn ($v) => is_scalar($v) ? (string) $v : '', $values), $allowed));

        return $values === [] ? null : [$column => $values];
    }

    /**
     * Keeps counts consistent with --count, one-to-one references, and the global row cap.
     *
     * @param  array<string, TablePlan>  $tables
     * @return array<string, TablePlan>
     */
    private function enforceCounts(array $tables, PlanOptions $options): array
    {
        $graph = $this->analysis->graph;
        $total = fn () => array_sum(array_map(fn (TablePlan $plan) => $plan->count, $tables));

        if ($options->count !== null && $total() > 0) {
            $factor = $options->count / $total();

            foreach ($tables as $name => $plan) {
                if ($plan->count > 0) {
                    $tables[$name] = $plan->withCount(max(1, (int) round($plan->count * $factor)));
                }
            }
        }

        if ($total() > $this->maxRows) {
            $this->warnings[] = "Scaled the plan down to the configured maximum of {$this->maxRows} rows.";
            $factor = $this->maxRows / $total();

            foreach ($tables as $name => $plan) {
                if ($plan->count > 0) {
                    $tables[$name] = $plan->withCount(max(1, (int) floor($plan->count * $factor)));
                }
            }
        }

        foreach ((new DependencyResolver)->resolve($graph)->tables as $name) {
            foreach ($graph->parentEdges($name) as $edge) {
                if (count($edge->columns) !== 1 || ! $this->analysis->schema->table($name)->isUnique($edge->columns[0])) {
                    continue;
                }

                $available = $tables[$edge->parent]->count + $options->existing($edge->parent);

                if ($tables[$name]->count > $available) {
                    $tables[$name] = $tables[$name]->withCount($available);
                }
            }
        }

        return $tables;
    }

    private function rate(mixed $value): float
    {
        return max(0.0, min(1.0, (float) $value));
    }

    private function finite(mixed $value): ?float
    {
        return is_numeric($value) && is_finite((float) $value) ? (float) $value : null;
    }
}
