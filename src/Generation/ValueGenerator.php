<?php

namespace Ayangzy\RealSeed\Generation;

use Ayangzy\RealSeed\Extension\ExtensionRegistry;
use Ayangzy\RealSeed\Extension\FieldContext;
use Ayangzy\RealSeed\Locale\FakerLocale;
use Ayangzy\RealSeed\Locale\LocaleProvider;
use Ayangzy\RealSeed\Planning\FieldPlan;
use Ayangzy\RealSeed\Schema\ColumnSchema;
use Ayangzy\RealSeed\Semantics\ReferenceData;
use Ayangzy\RealSeed\Semantics\Semantic;
use Carbon\CarbonImmutable;
use Closure;
use Faker\Generator as Faker;
use Illuminate\Support\Str;
use Throwable;

/**
 * Turns a field's semantic meaning into a concrete value.
 *
 * Values are coherent within a row (an email is built from the row's own name, a
 * slug from its title) and only use reserved domains (example.com/.org/.net), so
 * generated contact details can never reach a real person.
 */
final class ValueGenerator
{
    private const SAFE_EMAIL_DOMAINS = ['example.com', 'example.org', 'example.net'];

    private ?string $passwordHash = null;

    /**
     * @param  Closure(): string  $hashPassword
     */
    public function __construct(
        private readonly Faker $faker,
        private readonly TemporalGenerator $time,
        private readonly Closure $hashPassword,
        private readonly LocaleProvider $locale = new FakerLocale,
        private readonly ?ExtensionRegistry $extensions = null,
        private readonly string $appLocale = 'en',
    ) {
    }

    public function faker(): Faker
    {
        return $this->faker;
    }

    public function generate(FieldPlan $plan, ColumnSchema $column, RowState $row, SeededRandom $random): mixed
    {
        if (array_key_exists('value', $plan->options)) {
            return $plan->options['value'];
        }

        if ($column->nullable && ! $this->conditionsMet($plan, $row)) {
            return null;
        }

        if ($column->nullable && $random->chance((float) $plan->option('null_rate', 0.0))) {
            return null;
        }

        $context = new FieldContext($row->table, $column, $plan, $row, $this->faker, $random);

        // Developer code first, then plan samples, then the locale, then the defaults.
        if (($custom = $this->extensions?->fieldGenerator($row->table, $column->name, $plan->semantic)) !== null) {
            return $this->fit($custom->generate($context), $column);
        }

        if (is_string($plan->option('catalog')) && ($entry = $this->catalogValue($plan, $row)) !== null) {
            return $this->fit($entry, $column);
        }

        $samples = $plan->option('samples');

        if (is_array($samples) && $samples !== [] && ! $this->isTemporal($plan->semantic)) {
            return $this->fit($random->pick(array_values($samples)), $column);
        }

        if (($localized = $this->locale->value($plan->semantic, $context)) !== null) {
            return $this->fit($localized, $column);
        }

        return $this->fit($this->value($plan, $column, $row, $random), $column);
    }

    private function value(FieldPlan $plan, ColumnSchema $column, RowState $row, SeededRandom $random): mixed
    {
        $faker = $this->faker;

        return match ($plan->semantic) {
            Semantic::FIRST_NAME => $faker->firstName(),
            Semantic::LAST_NAME => $faker->lastName(),
            Semantic::FULL_NAME => $this->fullName($row),
            Semantic::EMAIL => $this->email($row, $random),
            Semantic::USERNAME => $this->username($row, $random),
            Semantic::PHONE => $faker->phoneNumber(),
            Semantic::URL => 'https://'.$this->domain($row),
            Semantic::DOMAIN => $this->domain($row),
            Semantic::IP => $faker->ipv4(),
            Semantic::IMAGE_URL => 'https://picsum.photos/seed/'.$random->string(10, 'abcdefghijklmnopqrstuvwxyz0123456789').'/640/480',

            Semantic::STREET => $faker->streetAddress(),
            Semantic::CITY => $faker->city(),
            Semantic::STATE => $this->tryFaker(['state', 'county', 'region'], fn () => $faker->city()),
            Semantic::POSTCODE => $faker->postcode(),
            Semantic::COUNTRY => $this->countryName(),
            Semantic::COUNTRY_CODE => $this->locale->countryCode(),
            Semantic::LATITUDE => round($faker->latitude(), 6),
            Semantic::LONGITUDE => round($faker->longitude(), 6),

            Semantic::COMPANY => $faker->company(),
            Semantic::JOB_TITLE => $faker->jobTitle(),

            Semantic::TITLE => Str::ucfirst($faker->bs()),
            Semantic::NAME => Str::title($faker->catchPhrase()),
            Semantic::SENTENCE => $this->text($faker, min(160, $column->maxLength() ?? 160)),
            Semantic::PARAGRAPH => $this->text($faker, $random->int(160, 600)),
            Semantic::SLUG => Str::slug($this->label($row) ?? $faker->words(3, true)),
            Semantic::CODE => $this->code($row, $column),
            Semantic::WORD => $faker->word(),

            Semantic::MONEY => $this->money($plan, $column, $random),
            Semantic::QUANTITY => $this->bounded($plan, $column, 1, 20, $random, skew: 2.0),
            Semantic::INTEGER => $this->bounded($plan, $column, 1, 1000, $random),
            Semantic::DECIMAL => $this->decimal($plan, $column, 0, 1000, $random),
            Semantic::PERCENTAGE => $this->percentage($plan, $column, $random),
            Semantic::RATING => $this->rating($column, $random),
            Semantic::BOOLEAN => $random->chance((float) $plan->option('true_rate', 0.5)),

            Semantic::CREATED_AT => $this->date($row->time, $column),
            Semantic::DELETED_AT => $this->date($this->time->past($row->time, $random), $column),
            Semantic::PAST, Semantic::FUTURE => $this->event($plan, $column, $row, $random),
            Semantic::BIRTH_DATE => $this->date($this->time->birthDate($random), $column),
            Semantic::TIME => sprintf('%02d:%02d:00', $random->int(7, 19), $random->pick([0, 15, 30, 45])),
            Semantic::YEAR => (int) date('Y', $this->time->past($row->time, $random)),

            Semantic::ENUM => $this->enum($plan, $column, $random),
            Semantic::UUID => $random->uuid(),
            Semantic::ULID => $random->ulid($row->time * 1000 + $random->int(0, 999)),
            Semantic::PASSWORD => $this->passwordHash ??= ($this->hashPassword)(),
            Semantic::TOKEN => $random->string(min(60, $column->maxLength() ?? 60)),
            Semantic::JSON => '[]',
            Semantic::COLOR => $faker->hexColor(),
            Semantic::CURRENCY => $this->locale->currency(),
            Semantic::CURRENCY_NAME => ReferenceData::CURRENCIES[$this->locale->currency()][0] ?? $this->locale->currency(),
            Semantic::CURRENCY_SYMBOL => ReferenceData::CURRENCIES[$this->locale->currency()][1] ?? $this->locale->currency(),
            Semantic::LOCALE => $this->appLocale,
            Semantic::TIMEZONE => $faker->timezone(),
            Semantic::NULL => $column->nullable ? null : '',

            default => $faker->word(),
        };
    }

    /**
     * The row's entry in a reference catalog; every column of the row uses the same entry.
     */
    private function catalogValue(FieldPlan $plan, RowState $row): ?string
    {
        $catalog = (string) $plan->option('catalog');
        $preferred = $catalog === 'currencies' ? $this->locale->currency() : $this->locale->countryCode();
        $entry = ReferenceData::entry($catalog, $row->sequence, $preferred);

        if ($entry === null) {
            return null;
        }

        return match ($plan->semantic) {
            Semantic::CURRENCY, Semantic::COUNTRY_CODE => $plan->option('alpha3') ? ($entry['alpha3'] ?? $entry['code']) : $entry['code'],
            Semantic::CURRENCY_NAME, Semantic::COUNTRY => $entry['name'],
            Semantic::CURRENCY_SYMBOL => $entry['symbol'] ?? null,
            default => null,
        };
    }

    /**
     * Whether "present_when" conditions (e.g. completed_at only when status = completed) hold.
     */
    private function conditionsMet(FieldPlan $plan, RowState $row): bool
    {
        foreach ((array) $plan->option('present_when', []) as $column => $values) {
            $current = $row->values[$column] ?? null;

            if ($current instanceof \BackedEnum) {
                $current = $current->value;
            }

            if (! in_array((string) $current, array_map('strval', (array) $values), true)) {
                return false;
            }
        }

        return true;
    }

    private function event(FieldPlan $plan, ColumnSchema $column, RowState $row, SeededRandom $random): CarbonImmutable|string
    {
        // Ends follow their start (ends_at after starts_at, end_date after start_date).
        if (preg_match('/^(.*?)(end|ends|finish|finished|until|expires|expiry|closed|closes)(_at|_on|_date|_time)?$/', $column->name, $m)) {
            foreach (['start', 'starts', 'begin', 'begins', 'opened', 'opens', 'from'] as $startWord) {
                foreach (['_at', '_on', '_date', '_time', ''] as $suffix) {
                    $sibling = $row->timestamps[$m[1].$startWord.$suffix] ?? null;

                    if ($sibling !== null) {
                        return $this->date($this->time->spanEnd($sibling, $random), $column);
                    }
                }
            }
        }

        $timestamp = $plan->semantic === Semantic::FUTURE
            ? $this->time->future($row->time, $random)
            : $this->time->past($row->time, $random);

        return $this->date($timestamp, $column);
    }

    private function date(int $timestamp, ColumnSchema $column): CarbonImmutable
    {
        $date = CarbonImmutable::createFromTimestamp($timestamp, date_default_timezone_get());

        return $column->family() === 'date' ? $date->startOfDay() : $date;
    }

    private function enum(FieldPlan $plan, ColumnSchema $column, SeededRandom $random): string|int
    {
        $weights = (array) $plan->option('weights', []);

        if ($weights === [] && $column->allowedValues !== null) {
            $weights = array_fill_keys($column->allowedValues, 1);
        }

        $value = $random->weighted($weights);

        return $column->family() === 'integer' ? (int) $value : (string) $value;
    }

    private function fullName(RowState $row): string
    {
        $first = $row->valueFor(Semantic::FIRST_NAME);
        $last = $row->valueFor(Semantic::LAST_NAME);

        return ($first ?? $this->faker->firstName()).' '.($last ?? $this->faker->lastName());
    }

    private function email(RowState $row, SeededRandom $random): string
    {
        $name = $row->valueFor(Semantic::FIRST_NAME) !== null
            ? $row->valueFor(Semantic::FIRST_NAME).' '.($row->valueFor(Semantic::LAST_NAME) ?? '')
            : ($row->valueFor(Semantic::FULL_NAME) ?? $this->faker->firstName().' '.$this->faker->lastName());

        $parts = array_values(array_filter(explode(' ', Str::lower(Str::ascii($name)))));
        $parts = array_map(fn ($part) => preg_replace('/[^a-z0-9]/', '', $part), $parts);

        $local = match ($random->int(0, 3)) {
            0 => implode('.', $parts),
            1 => ($parts[0][0] ?? 'x').implode('', array_slice($parts, 1)),
            2 => implode('', $parts).$random->int(1, 99),
            default => implode('_', $parts),
        };

        return trim($local, '._').'@'.$random->pick(self::SAFE_EMAIL_DOMAINS);
    }

    private function username(RowState $row, SeededRandom $random): string
    {
        $name = $row->valueFor(Semantic::FIRST_NAME) ?? $row->valueFor(Semantic::FULL_NAME) ?? $this->faker->userName();

        return preg_replace('/[^a-z0-9_]/', '', Str::lower(Str::ascii(str_replace(' ', '_', $name)))).$random->int(1, 999);
    }

    private function domain(RowState $row): string
    {
        $base = $row->valueFor(Semantic::COMPANY) ?? $this->label($row) ?? $this->faker->domainWord();

        return Str::slug(Str::limit($base, 30, '')).'.example.com';
    }

    /**
     * The row's human-readable label, if it has one yet (used for slugs and domains).
     */
    private function label(RowState $row): ?string
    {
        return $row->valueFor(Semantic::TITLE)
            ?? $row->valueFor(Semantic::NAME)
            ?? $row->valueFor(Semantic::COMPANY)
            ?? $row->valueFor(Semantic::FULL_NAME);
    }

    /**
     * Sequential, readable codes such as INV-000042 for invoices.number.
     */
    private function code(RowState $row, ColumnSchema $column): string
    {
        // Short columns get compact base-36 codes (001, 002, ... 00A) that stay unique.
        if (($max = $column->maxLength()) !== null && $max < 10) {
            return strtoupper(str_pad(base_convert((string) $row->sequence, 10, 36), $max, '0', STR_PAD_LEFT));
        }

        $prefix = strtoupper(substr(preg_replace('/[^a-z]/i', '', Str::singular($row->table)), 0, 3)) ?: 'REF';

        return sprintf('%s-%06d', $prefix, $row->sequence);
    }

    private function money(FieldPlan $plan, ColumnSchema $column, SeededRandom $random): int|float
    {
        [$min, $max] = match (true) {
            $plan->option('min') !== null || $plan->option('max') !== null => [(float) $plan->option('min', 1), (float) $plan->option('max', 1000)],
            (bool) preg_match('/salary/', $column->name) => [30000, 180000],
            (bool) preg_match('/budget|revenue/', $column->name) => [1000, 250000],
            (bool) preg_match('/price|fee|cost/', $column->name) => [5, 500],
            (bool) preg_match('/tax|discount/', $column->name) => [1, 100],
            default => [10, 2500],
        };

        // Log-uniform: many small amounts, fewer large ones.
        $value = exp(log(max($min, 0.01)) + (log(max($max, $min + 0.01)) - log(max($min, 0.01))) * $random->float());

        if (str_contains($column->name, 'cents')) {
            return (int) round($value * 100);
        }

        return $this->clampNumber($column->family() === 'integer' ? round($value) : round($value, 2), $column);
    }

    private function bounded(FieldPlan $plan, ColumnSchema $column, int $min, int $max, SeededRandom $random, float $skew = 1.0): int
    {
        $min = (int) $plan->option('min', $min);
        $max = (int) $plan->option('max', $max);

        $value = $min + (int) floor(($max - $min + 1) * ($random->float() ** $skew));

        return (int) $this->clampNumber(min($value, $max), $column);
    }

    private function decimal(FieldPlan $plan, ColumnSchema $column, float $min, float $max, SeededRandom $random): float
    {
        $min = (float) $plan->option('min', $min);
        $max = (float) $plan->option('max', $max);
        $scale = $column->precision()[1] ?? 2;

        return (float) $this->clampNumber(round($min + ($max - $min) * $random->float(), $scale), $column);
    }

    private function percentage(FieldPlan $plan, ColumnSchema $column, SeededRandom $random): int|float
    {
        $precision = $column->precision();

        // decimal(3,2) / decimal(5,4) style columns store fractions.
        if ($precision !== null && $precision[0] - $precision[1] <= 1) {
            return round($random->float(), $precision[1]);
        }

        return $column->family() === 'integer'
            ? $this->bounded($plan, $column, 0, 100, $random)
            : $this->decimal($plan, $column, 0, 100, $random);
    }

    private function rating(ColumnSchema $column, SeededRandom $random): int|float
    {
        $stars = (int) $random->weighted([5 => 35, 4 => 35, 3 => 15, 2 => 8, 1 => 7]);

        return $column->family() === 'integer' ? $stars : round(min(5, $stars - 0.5 + $random->float()), 1);
    }

    private function clampNumber(int|float $value, ColumnSchema $column): int|float
    {
        $max = match ($column->typeName) {
            'tinyint' => $column->isUnsigned() ? 255 : 127,
            'smallint', 'int2' => $column->isUnsigned() ? 65535 : 32767,
            default => null,
        };

        if (($precision = $column->precision()) !== null) {
            $max = 10 ** ($precision[0] - $precision[1]) - 10 ** -$precision[1];
        }

        return $max === null ? $value : min($value, $max);
    }

    private function text(Faker $faker, int $length): string
    {
        $length = max(10, $length);

        // realText() yields readable English; fall back to sentences where unavailable.
        return $this->tryFaker(['realText' => [$length]], fn () => Str::limit($faker->paragraph(), $length, ''));
    }

    private function countryName(): string
    {
        // Locale needs ext-intl, which isn't guaranteed.
        return class_exists(\Locale::class)
            ? (\Locale::getDisplayRegion('-'.$this->locale->countryCode(), 'en') ?: $this->faker->country())
            : $this->faker->country();
    }

    /**
     * @param  array<int|string, mixed>  $methods  Method names, or name => arguments.
     */
    private function tryFaker(array $methods, Closure $fallback): mixed
    {
        foreach ($methods as $method => $arguments) {
            if (is_int($method)) {
                [$method, $arguments] = [$arguments, []];
            }

            try {
                return $this->faker->{$method}(...$arguments);
            } catch (Throwable) {
                continue;
            }
        }

        return $fallback();
    }

    private function fit(mixed $value, ColumnSchema $column): mixed
    {
        if (is_string($value) && ($max = $column->maxLength()) !== null && mb_strlen($value) > $max) {
            return rtrim(mb_substr($value, 0, $max));
        }

        return $value;
    }

    private function isTemporal(string $semantic): bool
    {
        return in_array($semantic, [Semantic::CREATED_AT, Semantic::UPDATED_AT, Semantic::DELETED_AT, Semantic::PAST, Semantic::FUTURE, Semantic::BIRTH_DATE], true);
    }
}
