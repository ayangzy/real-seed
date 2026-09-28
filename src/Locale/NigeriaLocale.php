<?php

namespace Ayangzy\RealSeed\Locale;

use Ayangzy\RealSeed\Extension\FieldContext;
use Ayangzy\RealSeed\Semantics\Semantic;

/**
 * Nigerian names (Faker en_NG), real states and cities that agree with each other,
 * Nigerian mobile number formats, six-digit postal codes, and naira.
 */
final class NigeriaLocale extends FakerLocale
{
    /** State => major cities. */
    private const STATES = [
        'Lagos' => ['Ikeja', 'Lekki', 'Victoria Island', 'Surulere', 'Yaba', 'Ikorodu', 'Ajah'],
        'Federal Capital Territory' => ['Abuja', 'Gwagwalada', 'Kubwa', 'Garki', 'Wuse'],
        'Rivers' => ['Port Harcourt', 'Obio-Akpor', 'Bonny'],
        'Oyo' => ['Ibadan', 'Ogbomosho', 'Oyo'],
        'Kano' => ['Kano', 'Wudil'],
        'Kaduna' => ['Kaduna', 'Zaria'],
        'Enugu' => ['Enugu', 'Nsukka'],
        'Anambra' => ['Awka', 'Onitsha', 'Nnewi'],
        'Edo' => ['Benin City', 'Auchi', 'Ekpoma'],
        'Delta' => ['Asaba', 'Warri', 'Sapele'],
        'Ogun' => ['Abeokuta', 'Ijebu-Ode', 'Sagamu', 'Ota'],
        'Kwara' => ['Ilorin', 'Offa'],
        'Plateau' => ['Jos', 'Bukuru'],
        'Cross River' => ['Calabar', 'Ikom'],
        'Akwa Ibom' => ['Uyo', 'Eket', 'Ikot Ekpene'],
        'Imo' => ['Owerri', 'Orlu', 'Okigwe'],
        'Abia' => ['Umuahia', 'Aba'],
        'Osun' => ['Osogbo', 'Ile-Ife', 'Ilesa'],
        'Ondo' => ['Akure', 'Ondo', 'Owo'],
        'Ekiti' => ['Ado-Ekiti', 'Ikere-Ekiti'],
        'Borno' => ['Maiduguri', 'Biu'],
        'Bauchi' => ['Bauchi', 'Azare'],
        'Sokoto' => ['Sokoto'],
        'Niger' => ['Minna', 'Bida', 'Suleja'],
        'Benue' => ['Makurdi', 'Gboko', 'Otukpo'],
        'Kogi' => ['Lokoja', 'Okene'],
        'Nasarawa' => ['Lafia', 'Keffi'],
        'Adamawa' => ['Yola', 'Mubi'],
        'Gombe' => ['Gombe'],
        'Katsina' => ['Katsina', 'Funtua'],
        'Zamfara' => ['Gusau'],
        'Kebbi' => ['Birnin Kebbi'],
        'Jigawa' => ['Dutse'],
        'Yobe' => ['Damaturu', 'Potiskum'],
        'Taraba' => ['Jalingo'],
        'Bayelsa' => ['Yenagoa'],
        'Ebonyi' => ['Abakaliki'],
    ];

    /** Most people live in and around the largest cities. */
    private const STATE_WEIGHTS = ['Lagos' => 30, 'Federal Capital Territory' => 12, 'Rivers' => 8, 'Oyo' => 6, 'Kano' => 6];

    private const MOBILE_PREFIXES = ['0703', '0706', '0803', '0806', '0810', '0813', '0814', '0816', '0903', '0906', '0805', '0807', '0815', '0905', '0802', '0808', '0812', '0701', '0902', '0809', '0817', '0818', '0909'];

    public function __construct()
    {
        parent::__construct('en_NG', 'NGN');
    }

    public function value(string $semantic, FieldContext $context): mixed
    {
        return match ($semantic) {
            Semantic::STATE => $this->state($context),
            Semantic::CITY => $this->city($context),
            Semantic::POSTCODE => (string) $context->random->int(100001, 982002),
            Semantic::PHONE => $this->phone($context),
            Semantic::COUNTRY => 'Nigeria',
            default => null,
        };
    }

    private function state(FieldContext $context): string
    {
        $city = $context->valueFor(Semantic::CITY);

        foreach (self::STATES as $state => $cities) {
            if ($city !== null && in_array($city, $cities, true)) {
                return $state;
            }
        }

        return (string) $context->random->weighted(array_replace(array_fill_keys(array_keys(self::STATES), 1), self::STATE_WEIGHTS));
    }

    private function city(FieldContext $context): string
    {
        $state = $context->valueFor(Semantic::STATE) ?? $this->state($context);

        return $context->random->pick(self::STATES[$state] ?? self::STATES['Lagos']);
    }

    private function phone(FieldContext $context): string
    {
        $number = $context->random->pick(self::MOBILE_PREFIXES).$context->random->string(7, '0123456789');

        // Local (0803 123 4567) and international (+234 803 123 4567) styles both occur.
        return $context->random->chance(0.5)
            ? substr($number, 0, 4).' '.substr($number, 4, 3).' '.substr($number, 7)
            : '+234 '.substr($number, 1, 3).' '.substr($number, 4, 3).' '.substr($number, 7);
    }
}
