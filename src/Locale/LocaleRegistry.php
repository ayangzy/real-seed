<?php

namespace Ayangzy\RealSeed\Locale;

use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * Resolves --locale values: short country codes (ng, us, gb, ...), custom locales
 * registered in config, or any Faker locale such as pt_BR.
 */
final class LocaleRegistry
{
    private const BUILT_IN = [
        'us' => 'en_US', 'gb' => 'en_GB', 'ca' => 'en_CA', 'au' => 'en_AU', 'de' => 'de_DE', 'fr' => 'fr_FR',
        'ie' => 'en_IE', 'nz' => 'en_NZ', 'za' => 'en_ZA', 'in' => 'en_IN', 'es' => 'es_ES',
        'it' => 'it_IT', 'nl' => 'nl_NL', 'br' => 'pt_BR', 'mx' => 'es_MX', 'jp' => 'ja_JP',
    ];

    /**
     * @param  array<string, class-string<LocaleProvider>>  $custom
     */
    public function __construct(
        private readonly Container $container,
        private readonly array $custom = [],
        private readonly ?string $currency = null,
    ) {
    }

    public function resolve(string $code): LocaleProvider
    {
        $key = strtolower(trim($code));

        if (isset($this->custom[$key])) {
            $class = $this->custom[$key];

            if (! is_subclass_of($class, LocaleProvider::class)) {
                throw new InvalidArgumentException("[{$class}] is registered as locale [{$key}] but does not implement ".LocaleProvider::class.'.');
            }

            return $this->container->make($class);
        }

        if ($key === 'ng' || $key === 'en_ng') {
            return new NigeriaLocale;
        }

        if (isset(self::BUILT_IN[$key])) {
            return new FakerLocale(self::BUILT_IN[$key], $this->currency);
        }

        if (preg_match('/^([a-z]{2})_([a-z]{2})$/', $key, $m) && is_dir(dirname((new \ReflectionClass(\Faker\Factory::class))->getFileName()).'/Provider/'.$m[1].'_'.strtoupper($m[2]))) {
            return new FakerLocale($m[1].'_'.strtoupper($m[2]), $this->currency);
        }

        throw new InvalidArgumentException("Unknown locale [{$code}]. Use a country code such as ng, us or gb, a Faker locale such as pt_BR, or register one in config/realseed.php.");
    }
}
