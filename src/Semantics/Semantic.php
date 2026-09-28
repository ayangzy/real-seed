<?php

namespace Ayangzy\RealSeed\Semantics;

/**
 * The closed vocabulary of field meanings the deterministic generator understands.
 * Both the heuristic inferrer and the AI planner describe columns with these values,
 * which keeps AI output declarative: it can choose a meaning, never supply code.
 */
final class Semantic
{
    public const KEY = 'key';
    public const REFERENCE = 'reference';
    public const MORPH_TYPE = 'morph.type';
    public const MORPH_ID = 'morph.id';

    public const FIRST_NAME = 'person.first_name';
    public const LAST_NAME = 'person.last_name';
    public const FULL_NAME = 'person.name';
    public const EMAIL = 'internet.email';
    public const USERNAME = 'internet.username';
    public const URL = 'internet.url';
    public const DOMAIN = 'internet.domain';
    public const IP = 'internet.ip';
    public const PHONE = 'phone';

    public const STREET = 'address.street';
    public const CITY = 'address.city';
    public const STATE = 'address.state';
    public const POSTCODE = 'address.postcode';
    public const COUNTRY = 'address.country';
    public const COUNTRY_CODE = 'address.country_code';
    public const LATITUDE = 'geo.latitude';
    public const LONGITUDE = 'geo.longitude';

    public const COMPANY = 'company.name';
    public const JOB_TITLE = 'job.title';

    public const TITLE = 'text.title';
    public const NAME = 'text.name';
    public const SENTENCE = 'text.sentence';
    public const PARAGRAPH = 'text.paragraph';
    public const SLUG = 'text.slug';
    public const CODE = 'text.code';
    public const WORD = 'text.word';

    public const MONEY = 'number.money';
    public const QUANTITY = 'number.quantity';
    public const INTEGER = 'number.integer';
    public const DECIMAL = 'number.decimal';
    public const PERCENTAGE = 'number.percentage';
    public const RATING = 'number.rating';
    public const BOOLEAN = 'boolean';

    public const CREATED_AT = 'datetime.created';
    public const UPDATED_AT = 'datetime.updated';
    public const DELETED_AT = 'datetime.deleted';
    public const PAST = 'datetime.past';
    public const FUTURE = 'datetime.future';
    public const BIRTH_DATE = 'date.birth';
    public const TIME = 'time';
    public const YEAR = 'year';

    public const ENUM = 'enum';
    public const UUID = 'uuid';
    public const ULID = 'ulid';
    public const PASSWORD = 'password';
    public const TOKEN = 'token';
    public const JSON = 'json';
    public const COLOR = 'color';
    public const CURRENCY = 'currency';
    public const LOCALE = 'locale';
    public const TIMEZONE = 'timezone';
    public const IMAGE_URL = 'image.url';
    public const NULL = 'null';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return array_values((new \ReflectionClass(self::class))->getConstants());
    }
}
