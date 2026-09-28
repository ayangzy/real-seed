<?php

namespace AISeeder\Locale;

use AISeeder\Extension\FieldContext;

/**
 * Any Faker locale (en_US, de_DE, pt_BR, ...) with its country and currency.
 */
class FakerLocale implements LocaleProvider
{
    private const CURRENCIES = [
        'US' => 'USD', 'GB' => 'GBP', 'CA' => 'CAD', 'AU' => 'AUD', 'NZ' => 'NZD', 'IE' => 'EUR', 'DE' => 'EUR',
        'FR' => 'EUR', 'ES' => 'EUR', 'IT' => 'EUR', 'NL' => 'EUR', 'BE' => 'EUR', 'AT' => 'EUR', 'PT' => 'EUR',
        'FI' => 'EUR', 'GR' => 'EUR', 'CH' => 'CHF', 'SE' => 'SEK', 'NO' => 'NOK', 'DK' => 'DKK', 'PL' => 'PLN',
        'CZ' => 'CZK', 'NG' => 'NGN', 'GH' => 'GHS', 'KE' => 'KES', 'ZA' => 'ZAR', 'EG' => 'EGP', 'IN' => 'INR',
        'JP' => 'JPY', 'CN' => 'CNY', 'KR' => 'KRW', 'SG' => 'SGD', 'BR' => 'BRL', 'MX' => 'MXN', 'AR' => 'ARS',
        'TR' => 'TRY', 'RU' => 'RUB', 'UA' => 'UAH', 'IL' => 'ILS', 'SA' => 'SAR', 'AE' => 'AED', 'PH' => 'PHP',
        'ID' => 'IDR', 'MY' => 'MYR', 'TH' => 'THB', 'VN' => 'VND',
    ];

    public function __construct(
        private readonly string $fakerLocale = 'en_US',
        private readonly ?string $currency = null,
    ) {
    }

    public function fakerLocale(): string
    {
        return $this->fakerLocale;
    }

    public function countryCode(): string
    {
        return strtoupper(explode('_', $this->fakerLocale)[1] ?? 'US');
    }

    public function currency(): string
    {
        return $this->currency ?? self::CURRENCIES[$this->countryCode()] ?? 'USD';
    }

    public function value(string $semantic, FieldContext $context): mixed
    {
        return null;
    }
}
