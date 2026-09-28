<?php

namespace Ayangzy\RealSeed\Semantics;

/**
 * Real reference data for catalog tables such as currencies and countries. Rows use
 * entries by position, so a row's code, name and symbol always belong together.
 */
final class ReferenceData
{
    /** ISO 4217 code => [name, symbol] */
    public const CURRENCIES = [
        'USD' => ['US Dollar', '$'], 'EUR' => ['Euro', '€'], 'GBP' => ['British Pound', '£'],
        'NGN' => ['Nigerian Naira', '₦'], 'JPY' => ['Japanese Yen', '¥'], 'CAD' => ['Canadian Dollar', 'CA$'],
        'AUD' => ['Australian Dollar', 'A$'], 'CHF' => ['Swiss Franc', 'CHF'], 'CNY' => ['Chinese Yuan', 'CN¥'],
        'INR' => ['Indian Rupee', '₹'], 'ZAR' => ['South African Rand', 'R'], 'KES' => ['Kenyan Shilling', 'KSh'],
        'GHS' => ['Ghanaian Cedi', 'GH₵'], 'EGP' => ['Egyptian Pound', 'E£'], 'BRL' => ['Brazilian Real', 'R$'],
        'MXN' => ['Mexican Peso', 'MX$'], 'SEK' => ['Swedish Krona', 'kr'], 'NOK' => ['Norwegian Krone', 'kr'],
        'DKK' => ['Danish Krone', 'kr'], 'PLN' => ['Polish Złoty', 'zł'], 'TRY' => ['Turkish Lira', '₺'],
        'AED' => ['UAE Dirham', 'AED'], 'SAR' => ['Saudi Riyal', 'SAR'], 'SGD' => ['Singapore Dollar', 'S$'],
        'HKD' => ['Hong Kong Dollar', 'HK$'], 'KRW' => ['South Korean Won', '₩'], 'NZD' => ['New Zealand Dollar', 'NZ$'],
        'XOF' => ['West African CFA Franc', 'CFA'], 'MAD' => ['Moroccan Dirham', 'MAD'], 'IDR' => ['Indonesian Rupiah', 'Rp'],
    ];

    /** ISO 3166 alpha-2 => [alpha-3, name] */
    public const COUNTRIES = [
        'US' => ['USA', 'United States'], 'GB' => ['GBR', 'United Kingdom'], 'NG' => ['NGA', 'Nigeria'],
        'CA' => ['CAN', 'Canada'], 'AU' => ['AUS', 'Australia'], 'DE' => ['DEU', 'Germany'], 'FR' => ['FRA', 'France'],
        'ES' => ['ESP', 'Spain'], 'IT' => ['ITA', 'Italy'], 'NL' => ['NLD', 'Netherlands'], 'IE' => ['IRL', 'Ireland'],
        'ZA' => ['ZAF', 'South Africa'], 'KE' => ['KEN', 'Kenya'], 'GH' => ['GHA', 'Ghana'], 'EG' => ['EGY', 'Egypt'],
        'IN' => ['IND', 'India'], 'JP' => ['JPN', 'Japan'], 'CN' => ['CHN', 'China'], 'BR' => ['BRA', 'Brazil'],
        'MX' => ['MEX', 'Mexico'], 'SE' => ['SWE', 'Sweden'], 'NO' => ['NOR', 'Norway'], 'DK' => ['DNK', 'Denmark'],
        'PL' => ['POL', 'Poland'], 'TR' => ['TUR', 'Turkey'], 'AE' => ['ARE', 'United Arab Emirates'],
        'SG' => ['SGP', 'Singapore'], 'NZ' => ['NZL', 'New Zealand'], 'KR' => ['KOR', 'South Korea'], 'MA' => ['MAR', 'Morocco'],
    ];

    /**
     * The catalog entry for a row, with the locale's own entry first.
     *
     * @return array{code: string, name: string, symbol?: string, alpha3?: string}|null
     */
    public static function entry(string $catalog, int $sequence, string $preferred): ?array
    {
        $list = $catalog === 'currencies' ? self::CURRENCIES : self::COUNTRIES;

        if (isset($list[$preferred])) {
            $list = [$preferred => $list[$preferred]] + $list;
        }

        $codes = array_keys($list);
        $code = $codes[$sequence - 1] ?? null;

        if ($code === null) {
            return null;
        }

        return $catalog === 'currencies'
            ? ['code' => $code, 'name' => $list[$code][0], 'symbol' => $list[$code][1]]
            : ['code' => $code, 'alpha3' => $list[$code][0], 'name' => $list[$code][1]];
    }

    public static function size(string $catalog): int
    {
        return count($catalog === 'currencies' ? self::CURRENCIES : self::COUNTRIES);
    }
}
