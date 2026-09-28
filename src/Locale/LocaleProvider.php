<?php

namespace Ayangzy\RealSeed\Locale;

use Ayangzy\RealSeed\Extension\FieldContext;

/**
 * Locale-aware synthetic data. Register custom locales in config/realseed.php under
 * "locales", e.g. 'ke' => App\Seeding\KenyaLocale::class, then run --locale=ke.
 */
interface LocaleProvider
{
    /** The Faker locale for names, streets, and other defaults, e.g. "en_NG". */
    public function fakerLocale(): string;

    /** ISO 3166-1 alpha-2 country code, e.g. "NG". */
    public function countryCode(): string;

    /** ISO 4217 currency code, e.g. "NGN". */
    public function currency(): string;

    /**
     * A locale-specific value for the semantic, or null to use the default generator.
     */
    public function value(string $semantic, FieldContext $context): mixed;
}
