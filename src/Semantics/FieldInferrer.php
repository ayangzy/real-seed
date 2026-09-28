<?php

namespace Ayangzy\RealSeed\Semantics;

use Ayangzy\RealSeed\Analysis\EnumInfo;
use Ayangzy\RealSeed\Analysis\ProjectAnalysis;
use Ayangzy\RealSeed\Planning\FieldPlan;
use Ayangzy\RealSeed\Schema\ColumnSchema;
use Ayangzy\RealSeed\Schema\TableSchema;
use Illuminate\Support\Str;

/**
 * Deterministic column interpretation from names, types, casts, and the graph.
 * This is the baseline; the AI planner refines it rather than replacing it.
 */
final class FieldInferrer
{
    private const PERSON_ENTITIES = [
        'user', 'customer', 'client', 'patient', 'member', 'employee', 'author', 'contact', 'person', 'people',
        'student', 'doctor', 'agent', 'staff', 'owner', 'teacher', 'lecturer', 'tenant', 'guest', 'subscriber',
        'candidate', 'applicant', 'driver', 'nurse', 'instructor', 'player', 'volunteer', 'lead', 'buyer', 'seller',
    ];

    private const COMPANY_ENTITIES = [
        'organization', 'organisation', 'company', 'business', 'vendor', 'supplier', 'hospital', 'school',
        'university', 'clinic', 'agency', 'firm', 'brand', 'merchant', 'store', 'shop', 'workspace', 'team',
    ];

    private const POSITIVE_STATES = [
        'active', 'completed', 'complete', 'done', 'paid', 'published', 'approved', 'confirmed', 'success',
        'successful', 'delivered', 'closed', 'resolved', 'accepted', 'enabled', 'open', 'verified', 'fulfilled', 'shipped',
    ];

    private const NEGATIVE_STATES = [
        'cancelled', 'canceled', 'failed', 'failure', 'suspended', 'rejected', 'refunded', 'no_show', 'noshow',
        'banned', 'deleted', 'blocked', 'expired', 'declined', 'error', 'disputed', 'void', 'lost', 'churned',
    ];

    private const FUTURE_PATTERNS = [
        'scheduled_', 'starts_', 'start_', 'due_', 'expires_', 'expiry', 'expiration', 'ends_', 'end_',
        'deadline', '_until', 'renews_', 'renewal_', 'trial_ends', 'appointment_', 'next_',
    ];

    /** Timestamp stems and the state values that also mean "this happened". */
    private const WORKFLOW_SYNONYMS = [
        'compl' => ['done', 'finished', 'resolved', 'closed'],
        'finis' => ['done', 'completed', 'complete'],
        'cance' => ['canceled', 'cancelled', 'void', 'voided'],
        'paid' => ['settled'],
        'deliv' => ['received'],
        'appro' => ['accepted'],
        'publi' => ['live'],
        'resol' => ['closed', 'done'],
    ];

    public function __construct(private readonly ProjectAnalysis $analysis)
    {
    }

    /**
     * @return array<string, FieldPlan> Plans for every column the generator must fill.
     */
    public function inferTable(TableSchema $table): array
    {
        $fields = [];

        foreach ($table->columns as $column) {
            $plan = $this->infer($table, $column);

            if ($plan !== null) {
                $fields[$column->name] = $plan;
            }
        }

        return $fields;
    }

    public function infer(TableSchema $table, ColumnSchema $column): ?FieldPlan
    {
        if ($column->generated) {
            return null;
        }

        $plan = $this->structural($table, $column)
            ?? $this->fromEnum($table, $column)
            ?? $this->fromCast($table, $column)
            ?? $this->fromCatalog($table, $column)
            ?? $this->fromName($table, $column)
            ?? $this->fromType($column);

        return $this->withNullability($table, $column, $plan);
    }

    private function structural(TableSchema $table, ColumnSchema $column): ?FieldPlan
    {
        $graph = $this->analysis->graph;

        if ($table->primaryKey === [$column->name]) {
            $model = $this->analysis->model($table->name);

            $strategy = match (true) {
                $model?->uniqueIdType !== null => $model->uniqueIdType,
                $column->family() === 'uuid' => 'uuid',
                $column->family() === 'integer' => 'increment',
                str_contains($column->type, 'char(26)') => 'ulid',
                default => 'string',
            };

            return new FieldPlan(Semantic::KEY, ['strategy' => $strategy]);
        }

        foreach ($graph->morphSlots($table->name) as $slot) {
            if ($slot->typeColumn === $column->name) {
                return new FieldPlan(Semantic::MORPH_TYPE, ['slot' => $slot->name]);
            }

            if ($slot->idColumn === $column->name) {
                return new FieldPlan(Semantic::MORPH_ID, ['slot' => $slot->name]);
            }
        }

        if (($edge = $graph->edgeForColumn($table->name, $column->name)) !== null) {
            return new FieldPlan(Semantic::REFERENCE, ['selection' => $graph->isPivot($table->name) ? 'uniform' : 'skewed']);
        }

        return null;
    }

    private function fromEnum(TableSchema $table, ColumnSchema $column): ?FieldPlan
    {
        $enum = $this->analysis->enum($table->name, $column->name);

        return $enum === null ? null : new FieldPlan(Semantic::ENUM, ['weights' => $this->enumWeights($enum)]);
    }

    private function fromCast(TableSchema $table, ColumnSchema $column): ?FieldPlan
    {
        $cast = strtolower((string) ($this->analysis->model($table->name)?->casts[$column->name] ?? ''));

        return match (true) {
            $cast === 'hashed' => new FieldPlan(Semantic::PASSWORD),
            in_array($cast, ['bool', 'boolean'], true) => new FieldPlan(Semantic::BOOLEAN, ['true_rate' => $this->trueRate($column->name)]),
            in_array($cast, ['array', 'json', 'object', 'collection', 'encrypted:array', 'encrypted:collection', 'encrypted:object'], true),
            str_contains($cast, 'asarrayobject'), str_contains($cast, 'ascollection') => new FieldPlan(Semantic::JSON),
            default => null,
        };
    }

    /**
     * Reference tables such as currencies and countries get real, matching entries
     * (USD / US Dollar / $) instead of invented values.
     */
    private function fromCatalog(TableSchema $table, ColumnSchema $column): ?FieldPlan
    {
        if (! in_array($column->family(), ['string', 'text'], true)) {
            return null;
        }

        $entity = Str::afterLast(Str::singular(strtolower($table->name)), '_');
        $name = strtolower($column->name);

        return match ($entity) {
            'currency' => match (true) {
                in_array($name, ['code', 'iso', 'iso_code', 'currency_code', 'alpha_code'], true) => new FieldPlan(Semantic::CURRENCY, ['catalog' => 'currencies']),
                in_array($name, ['name', 'title', 'label'], true) => new FieldPlan(Semantic::CURRENCY_NAME, ['catalog' => 'currencies']),
                in_array($name, ['symbol', 'sign'], true) => new FieldPlan(Semantic::CURRENCY_SYMBOL, ['catalog' => 'currencies']),
                default => null,
            },
            'country' => match (true) {
                in_array($name, ['iso3', 'alpha3', 'alpha_3', 'iso_alpha3'], true) => new FieldPlan(Semantic::COUNTRY_CODE, ['catalog' => 'countries', 'alpha3' => true]),
                in_array($name, ['code', 'iso', 'iso2', 'iso_code', 'alpha2', 'alpha_2', 'country_code'], true)
                    => new FieldPlan(Semantic::COUNTRY_CODE, ['catalog' => 'countries', 'alpha3' => $column->maxLength() === 3]),
                in_array($name, ['name', 'title', 'label'], true) => new FieldPlan(Semantic::COUNTRY, ['catalog' => 'countries']),
                default => null,
            },
            default => null,
        };
    }

    private function fromName(TableSchema $table, ColumnSchema $column): ?FieldPlan
    {
        $name = strtolower($column->name);
        $family = $column->family();
        $numeric = in_array($family, ['integer', 'decimal'], true);
        $textual = in_array($family, ['string', 'text'], true);
        $temporal = in_array($family, ['date', 'datetime'], true);

        $is = fn (string ...$names) => in_array($name, $names, true);
        $matches = fn (string $pattern) => (bool) preg_match($pattern, $name);

        $semantic = match (true) {
            $name === 'created_at' => Semantic::CREATED_AT,
            $name === 'updated_at' => Semantic::UPDATED_AT,
            $name === 'deleted_at' => Semantic::DELETED_AT,

            $is('date_of_birth', 'dob', 'birthdate', 'birth_date', 'birthday') => Semantic::BIRTH_DATE,
            $temporal || ($matches('/(_at|_on|_date)$/') && ! $numeric) => $this->isFutureName($name) ? Semantic::FUTURE : Semantic::PAST,

            ! $textual && ! $numeric && $family !== 'boolean' => null,

            $is('first_name', 'firstname', 'given_name', 'forename') => Semantic::FIRST_NAME,
            $is('last_name', 'lastname', 'surname', 'family_name') => Semantic::LAST_NAME,
            $is('full_name', 'display_name', 'contact_name') || $matches('/^('.implode('|', self::PERSON_ENTITIES).')_name$/') => Semantic::FULL_NAME,
            $is('company', 'company_name', 'business_name', 'organization_name', 'organisation_name', 'employer') => Semantic::COMPANY,
            $name === 'name' => $this->entityNameSemantic($table),
            $textual && $matches('/^[a-z0-9]+_name$/') => Semantic::NAME,

            $name === 'email' || str_ends_with($name, '_email') => Semantic::EMAIL,
            $is('username', 'user_name', 'handle', 'login', 'nickname') => Semantic::USERNAME,
            $matches('/(^|_)(phone|mobile|telephone|tel|cell)(_|$)/') || $is('contact_number') => Semantic::PHONE,
            $name === 'password' || $name === 'password_hash' => Semantic::PASSWORD,
            $matches('/(^|_)(token|api_key|secret|hash)$/') => Semantic::TOKEN,
            $matches('/(^|_)(avatar|image|photo|logo|thumbnail|picture|cover)(_url|_path)?$/') => Semantic::IMAGE_URL,
            $is('url', 'website', 'link', 'homepage') || str_ends_with($name, '_url') => Semantic::URL,
            $name === 'domain' => Semantic::DOMAIN,
            $is('ip', 'ip_address') || str_ends_with($name, '_ip') => Semantic::IP,

            $is('address', 'address_line_1', 'address_line1', 'address1', 'street', 'street_address', 'address_line_2', 'address2') => Semantic::STREET,
            $is('city', 'town') => Semantic::CITY,
            $is('state', 'province', 'region', 'county') && $textual => Semantic::STATE,
            $is('zip', 'zipcode', 'zip_code', 'postcode', 'postal_code') => Semantic::POSTCODE,
            $is('country_code') || ($name === 'country' && $column->maxLength() !== null && $column->maxLength() <= 3) => Semantic::COUNTRY_CODE,
            $name === 'country' => Semantic::COUNTRY,
            $is('lat', 'latitude') => Semantic::LATITUDE,
            $is('lng', 'lon', 'long', 'longitude') => Semantic::LONGITUDE,
            $is('job_title', 'occupation', 'designation', 'role_title') || ($name === 'position' && $textual) => Semantic::JOB_TITLE,

            $is('title', 'subject', 'headline') => Semantic::TITLE,
            $name === 'slug' || str_ends_with($name, '_slug') => Semantic::SLUG,
            $textual && ($is('code', 'sku', 'reference', 'ref') || $matches('/_(code|number|no|ref|reference)$/')) => Semantic::CODE,
            $textual && $matches('/(^|_)(description|body|content|bio|about|notes?|message|comment|summary|details|text|excerpt|reason|instructions)$/')
                => $family === 'text' ? Semantic::PARAGRAPH : Semantic::SENTENCE,

            $numeric && $matches('/(^|_)(price|amount|total|subtotal|cost|fee|balance|salary|budget|revenue|tax|discount|value)$/') => Semantic::MONEY,
            $numeric && $matches('/(^|_)(quantity|qty|stock|count|seats|capacity|units|guests)$/') => Semantic::QUANTITY,
            $numeric && $matches('/(^|_)(rating|stars|score)$/') => Semantic::RATING,
            $numeric && $matches('/(^|_)(percent|percentage|rate|progress)$/') => Semantic::PERCENTAGE,
            ($family === 'boolean' || $numeric) && $matches('/^(is|has|can|should|was)_|^(active|enabled|verified|published|featured|visible|archived|approved|paid)$/') => Semantic::BOOLEAN,

            $is('color', 'colour') => Semantic::COLOR,
            $is('currency', 'currency_code') => Semantic::CURRENCY,
            $is('locale', 'language', 'lang') => Semantic::LOCALE,
            $is('timezone', 'time_zone', 'tz') => Semantic::TIMEZONE,
            $name === 'year' => Semantic::YEAR,
            $is('uuid', 'guid') || str_ends_with($name, '_uuid') => Semantic::UUID,
            $name === 'ulid' => Semantic::ULID,
            default => null,
        };

        if ($semantic === null) {
            return null;
        }

        $options = [];

        if (in_array($semantic, [Semantic::TITLE, Semantic::NAME, Semantic::SENTENCE, Semantic::PARAGRAPH], true)) {
            if (preg_match('/^([a-z0-9]+)_name$/', $name, $m)) {
                $options['entity'] = $m[1]; // product_name -> product vocabulary, bank_name -> banks
            }

            if ($table->isUnique($column->name)) {
                $options['unique'] = true;
            }
        }

        return match ($semantic) {
            Semantic::TITLE, Semantic::NAME, Semantic::SENTENCE, Semantic::PARAGRAPH => new FieldPlan($semantic, $options),
            Semantic::BOOLEAN => new FieldPlan($semantic, ['true_rate' => $this->trueRate($name)]),
            Semantic::PAST, Semantic::FUTURE => new FieldPlan($semantic, $this->workflowCondition($table, $column)),
            default => new FieldPlan($semantic),
        };
    }

    private function fromType(ColumnSchema $column): FieldPlan
    {
        return new FieldPlan(match ($column->family()) {
            'boolean' => Semantic::BOOLEAN,
            'integer' => Semantic::INTEGER,
            'decimal' => Semantic::DECIMAL,
            'date', 'datetime' => Semantic::PAST,
            'time' => Semantic::TIME,
            'year' => Semantic::YEAR,
            'json' => Semantic::JSON,
            'uuid' => Semantic::UUID,
            'text' => Semantic::PARAGRAPH,
            'binary' => Semantic::NULL,
            default => Semantic::WORD,
        }, $column->family() === 'boolean' ? ['true_rate' => 0.5] : []);
    }

    private function withNullability(TableSchema $table, ColumnSchema $column, FieldPlan $plan): FieldPlan
    {
        if (! $column->nullable || array_key_exists('null_rate', $plan->options) || isset($plan->options['present_when'])) {
            return $plan;
        }

        $rate = match ($plan->semantic) {
            Semantic::KEY, Semantic::ENUM, Semantic::BOOLEAN, Semantic::CREATED_AT, Semantic::UPDATED_AT, Semantic::MORPH_TYPE, Semantic::MORPH_ID => 0.0,
            Semantic::DELETED_AT => 0.92,
            // Ownership is set even when the column is nullable; assignment-style links often aren't.
            Semantic::REFERENCE => match (true) {
                (bool) preg_match('/^(owner|creator|created_by|author|user)(_id)?$/', $column->name) => 0.0,
                (bool) $this->analysis->graph->edgeForColumn($table->name, $column->name)?->isSelfReferencing() => 0.3,
                default => 0.15,
            },
            Semantic::PAST, Semantic::FUTURE => 0.25,
            Semantic::NULL => 1.0,
            default => 0.15,
        };

        return $plan->with(['null_rate' => $rate]);
    }

    private function entityNameSemantic(TableSchema $table): string
    {
        $entity = Str::singular(strtolower($table->name));
        $entity = Str::afterLast($entity, '_');

        return match (true) {
            in_array($entity, self::PERSON_ENTITIES, true), $table->hasColumn('email') && $table->hasColumn('password') => Semantic::FULL_NAME,
            in_array($entity, self::COMPANY_ENTITIES, true) => Semantic::COMPANY,
            default => Semantic::NAME,
        };
    }

    private function isFutureName(string $name): bool
    {
        foreach (self::FUTURE_PATTERNS as $pattern) {
            if (str_contains($name, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Links timestamps to workflow states on the same row, e.g. completed_at is only
     * set when status is "completed", cancelled_at only when "cancelled".
     */
    private function workflowCondition(TableSchema $table, ColumnSchema $column): array
    {
        if (! $column->nullable) {
            return [];
        }

        $stem = $this->stem(preg_replace('/(_at|_on|_date)$/', '', $column->name));

        if (strlen($stem) < 4) {
            return [];
        }

        foreach ($table->columns as $other) {
            $enum = $this->analysis->enum($table->name, $other->name);

            if ($enum === null) {
                continue;
            }

            $synonyms = self::WORKFLOW_SYNONYMS[$stem] ?? [];

            $values = array_values(array_filter(
                $enum->values,
                fn ($value) => $this->stem((string) $value) === $stem || in_array(strtolower((string) $value), $synonyms, true),
            ));

            if ($values !== []) {
                return ['present_when' => [$other->name => $values]];
            }
        }

        return [];
    }

    private function stem(string $word): string
    {
        return substr(preg_replace('/[^a-z]/', '', strtolower($word)), 0, 5);
    }

    /**
     * @return array<string, float>
     */
    private function enumWeights(EnumInfo $enum): array
    {
        $weights = [];

        foreach ($enum->values as $value) {
            $normalized = strtolower((string) $value);

            $weights[(string) $value] = match (true) {
                in_array($normalized, self::POSITIVE_STATES, true) => 6.0,
                in_array($normalized, self::NEGATIVE_STATES, true) => 0.6,
                default => 2.0,
            };
        }

        return $weights;
    }

    private function trueRate(string $name): float
    {
        return match (true) {
            (bool) preg_match('/(active|enabled|verified|visible|published|approved|paid|available)$/', $name) => 0.85,
            (bool) preg_match('/(admin|deleted|blocked|banned|archived|suspended|locked|spam|test|featured)$/', $name) => 0.1,
            default => 0.5,
        };
    }
}
