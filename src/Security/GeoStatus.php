<?php

declare(strict_types=1);

namespace Larena\Env\Security;

/**
 * Country blocking (security:geo-status, simai-geo/1, simai-env 1.48.0): off, deny a list of countries or allow only
 * a list, on the web ports or on all of them; whether the rules are loaded, how many connections they dropped, the
 * country database and the line its licence asks to show (DB-IP, CC BY 4.0).
 */
final readonly class GeoStatus
{
    public const string SCHEMA = 'simai-geo/1';
    public const array MODES = ['off', 'deny', 'allow'];
    public const array PORTS = ['web', 'all'];
    /** The countries of ISO 3166-1 alpha-2, which the server's database names. */
    public const array COUNTRIES = [
        'AD', 'AE', 'AF', 'AG', 'AI', 'AL', 'AM', 'AO', 'AQ', 'AR', 'AS', 'AT', 'AU', 'AW', 'AX', 'AZ', 'BA', 'BB', 'BD', 'BE', 'BF', 'BG', 'BH', 'BI', 'BJ',
        'BL', 'BM', 'BN', 'BO', 'BQ', 'BR', 'BS', 'BT', 'BV', 'BW', 'BY', 'BZ', 'CA', 'CC', 'CD', 'CF', 'CG', 'CH', 'CI', 'CK', 'CL', 'CM', 'CN', 'CO', 'CR',
        'CU', 'CV', 'CW', 'CX', 'CY', 'CZ', 'DE', 'DJ', 'DK', 'DM', 'DO', 'DZ', 'EC', 'EE', 'EG', 'EH', 'ER', 'ES', 'ET', 'FI', 'FJ', 'FK', 'FM', 'FO', 'FR',
        'GA', 'GB', 'GD', 'GE', 'GF', 'GG', 'GH', 'GI', 'GL', 'GM', 'GN', 'GP', 'GQ', 'GR', 'GS', 'GT', 'GU', 'GW', 'GY', 'HK', 'HM', 'HN', 'HR', 'HT', 'HU',
        'ID', 'IE', 'IL', 'IM', 'IN', 'IO', 'IQ', 'IR', 'IS', 'IT', 'JE', 'JM', 'JO', 'JP', 'KE', 'KG', 'KH', 'KI', 'KM', 'KN', 'KP', 'KR', 'KW', 'KY', 'KZ',
        'LA', 'LB', 'LC', 'LI', 'LK', 'LR', 'LS', 'LT', 'LU', 'LV', 'LY', 'MA', 'MC', 'MD', 'ME', 'MF', 'MG', 'MH', 'MK', 'ML', 'MM', 'MN', 'MO', 'MP', 'MQ',
        'MR', 'MS', 'MT', 'MU', 'MV', 'MW', 'MX', 'MY', 'MZ', 'NA', 'NC', 'NE', 'NF', 'NG', 'NI', 'NL', 'NO', 'NP', 'NR', 'NU', 'NZ', 'OM', 'PA', 'PE', 'PF',
        'PG', 'PH', 'PK', 'PL', 'PM', 'PN', 'PR', 'PS', 'PT', 'PW', 'PY', 'QA', 'RE', 'RO', 'RS', 'RU', 'RW', 'SA', 'SB', 'SC', 'SD', 'SE', 'SG', 'SH', 'SI',
        'SJ', 'SK', 'SL', 'SM', 'SN', 'SO', 'SR', 'SS', 'ST', 'SV', 'SX', 'SY', 'SZ', 'TC', 'TD', 'TF', 'TG', 'TH', 'TJ', 'TK', 'TL', 'TM', 'TN', 'TO', 'TR',
        'TT', 'TV', 'TW', 'TZ', 'UA', 'UG', 'UM', 'US', 'UY', 'UZ', 'VA', 'VC', 'VE', 'VG', 'VI', 'VN', 'VU', 'WF', 'WS', 'YE', 'YT', 'ZA', 'ZM', 'ZW',
    ];

    /** The licence's line when the server names none (DB-IP Lite, CC BY 4.0). */
    public const string ATTRIBUTION = 'IP to country data by DB-IP (https://db-ip.com), CC BY 4.0';

    /** @param list<string> $countries ISO 3166 alpha-2 codes */
    public function __construct(
        public string $mode,
        public array $countries,
        public string $ports,
        public bool $active,
        public ?int $dropped,
        public bool $databasePresent,
        public ?string $databaseDate,
        public ?string $databaseLoadedAt,
        public string $attribution,
    ) {
    }

    /** @param array<array-key, mixed> $data */
    public static function fromData(array $data): ?self
    {
        if (($data['schema'] ?? null) !== self::SCHEMA) {
            return null;
        }
        $database = is_array($data['database'] ?? null) ? $data['database'] : [];
        $text = static fn (mixed $value): ?string => is_string($value) && $value !== '' ? mb_substr($value, 0, 64) : null;
        $attribution = is_string($data['attribution'] ?? null) && trim($data['attribution']) !== '' ? mb_substr(trim($data['attribution']), 0, 200) : self::ATTRIBUTION;

        return new self(
            mode: in_array($data['mode'] ?? null, self::MODES, true) ? $data['mode'] : 'off',
            countries: self::codes($data['countries'] ?? []),
            ports: in_array($data['ports'] ?? null, self::PORTS, true) ? $data['ports'] : 'web',
            active: ($data['active'] ?? false) === true,
            dropped: is_int($data['dropped_packets'] ?? null) ? $data['dropped_packets'] : null,
            databasePresent: ($database['present'] ?? false) === true,
            databaseDate: $text($database['date'] ?? null),
            databaseLoadedAt: $text($database['downloaded_at'] ?? null),
            attribution: $attribution,
        );
    }

    /**
     * Country codes from a list or a comma-separated line, upper case, each once, in the order given.
     *
     * @return list<string>
     */
    public static function codes(mixed $value): array
    {
        $items = is_array($value) ? $value : (is_string($value) ? explode(',', $value) : []);
        $codes = [];
        foreach ($items as $item) {
            $code = is_string($item) ? strtoupper(trim($item)) : '';
            if (preg_match('/\A[A-Z]{2}\z/D', $code) === 1 && !in_array($code, $codes, true)) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    /** The country's flag: its two regional indicator letters. */
    public static function flag(string $code): string
    {
        if (preg_match('/\A[A-Z]{2}\z/D', $code) !== 1) {
            return '';
        }

        return mb_chr(0x1F1E6 + ord($code[0]) - 65).mb_chr(0x1F1E6 + ord($code[1]) - 65);
    }

    /** The country's name in the locale, or its code where the system cannot name it. */
    public static function name(string $code, string $locale): string
    {
        if (class_exists(\Locale::class)) {
            $name = \Locale::getDisplayRegion('-'.$code, $locale);
            if (is_string($name) && $name !== '' && $name !== $code) {
                return $name;
            }
        }

        return $code;
    }
}
