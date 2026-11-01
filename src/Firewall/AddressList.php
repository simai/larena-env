<?php

declare(strict_types=1);

namespace Larena\Env\Firewall;

/**
 * The addresses and networks typed in a form, one per line or separated by commas or spaces, and whether an address
 * is among them: the panel warns before a person shuts themselves out (Panel plan 5.3). The server checks every
 * address again.
 */
final class AddressList
{
    private function __construct()
    {
    }

    /** @return list<string>|null the addresses, or null when one of them is not an address or network */
    public static function parse(string $typed): ?array
    {
        $items = preg_split('/[\s,;]+/', trim($typed), -1, PREG_SPLIT_NO_EMPTY);
        $list = [];
        foreach ($items === false ? [] : $items as $item) {
            if (!self::valid($item)) {
                return null;
            }
            $list[] = $item;
        }

        return array_values(array_unique($list));
    }

    public static function valid(string $item): bool
    {
        [$address, $bits] = array_pad(explode('/', $item, 2), 2, null);
        $packed = @inet_pton($address);
        if ($packed === false) {
            return false;
        }
        if ($bits === null) {
            return true;
        }

        return preg_match('/\A[0-9]{1,3}\z/D', $bits) === 1 && (int) $bits <= strlen($packed) * 8;
    }

    /** @param list<string> $list */
    public static function contains(array $list, string $address): bool
    {
        $packed = @inet_pton($address);
        if ($packed === false) {
            return false;
        }
        foreach ($list as $item) {
            [$network, $bits] = array_pad(explode('/', $item, 2), 2, null);
            $base = @inet_pton($network);
            if ($base === false || strlen($base) !== strlen($packed)) {
                continue;
            }
            $length = $bits === null ? strlen($packed) * 8 : (int) $bits;
            $bytes = intdiv($length, 8);
            $rest = $length % 8;
            if (substr($base, 0, $bytes) !== substr($packed, 0, $bytes)) {
                continue;
            }
            if ($rest === 0) {
                return true;
            }
            $mask = (0xFF << (8 - $rest)) & 0xFF;
            if ((ord($base[$bytes]) & $mask) === (ord($packed[$bytes]) & $mask)) {
                return true;
            }
        }

        return false;
    }
}
