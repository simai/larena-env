<?php

declare(strict_types=1);

namespace Larena\Env\Software;

use Larena\Env\Server\SiteProfile;

/**
 * Which PHP versions a site may run and which of them are old (owner decision via the auditor, msg-93e8e022, 2026-11-18):
 * a site may use PHP 7.4 and 8.0, marked «устарела, небезопасна»; 5.6 never. The bounds are the server's (self:profiles
 * `php.versions` and `php.min` of the site's kind); the mark is the server's flag where it gives one, else this one list.
 */
final class PhpSupport
{
    /** The versions a site may still use but that no longer get security fixes: the one list the panel marks by. */
    public const array OUTDATED = ['7.4', '8.0'];

    private function __construct()
    {
    }

    /** Whether a version is marked old and unsafe: the server's word where it says one, else the list. */
    public static function outdated(string $version, ?bool $server = null): bool
    {
        return $server ?? in_array($version, self::OUTDATED, true);
    }

    /**
     * The versions a site of this kind may be switched to: the server's list for the kind, else the installed versions
     * from its lowest one up; the site's own version stays offered (selected) even where it is below the bound.
     *
     * @param list<string> $installed
     * @return list<string>
     */
    public static function forSite(?SiteProfile $profile, array $installed, string $current): array
    {
        if ($profile?->phpVersions !== null) {
            $allowed = $profile->phpVersions;
        } else {
            $min = $profile?->phpMin;
            $allowed = array_values(array_filter($installed, static fn (string $version): bool => $min === null || version_compare($version, $min, '>=')));
        }
        if ($current !== 'none' && preg_match('/\A[5-9]\.[0-9]\z/D', $current) === 1 && !in_array($current, $allowed, true)) {
            $allowed[] = $current;
        }
        usort($allowed, static fn (string $a, string $b): int => version_compare($b, $a));

        return array_values(array_unique($allowed));
    }

    /** «7.4 · устарела, небезопасна», or the version alone. */
    public static function label(string $version, string $mark): string
    {
        return self::outdated($version) ? $version.' · '.$mark : $version;
    }
}
