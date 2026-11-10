<?php

declare(strict_types=1);

namespace Larena\Env\Admin;

use Larena\Env\Server\Moment;
use Larena\Env\Server\SiteDoctor;

/**
 * The rows of the site's «Проверка» block, ready to print: what did not pass, in the server's order, each with its
 * words, what the server found, since when, and the fix it offers; and the profile's rules that pass, for the list
 * under the count. A check of an older server (no rules) gives the rows it always gave and no list.
 */
final class SiteChecks
{
    private function __construct()
    {
    }

    /**
     * @param bool $fixes whether this server's gateway lists site:check-fix: without it no fix is offered
     *
     * @return list<array{status: string, tone: string, label: string, title: string, text: string, area: string, key: ?string, detail: ?string, since: ?string, fix: ?string}>
     */
    public static function problems(?SiteDoctor $doctor, bool $fixes = false): array
    {
        return array_map(static fn (array $check): array => self::row($check, $fixes), $doctor->problems ?? []);
    }

    /** @return list<array{status: string, tone: string, label: string, title: string, text: string, area: string, key: ?string, detail: ?string, since: ?string, fix: ?string}> */
    public static function passed(?SiteDoctor $doctor): array
    {
        return array_map(static fn (array $check): array => self::row($check, false), $doctor->passedRules ?? []);
    }

    /**
     * @param array{status: string, area: string, title: string, key?: ?string, fix?: ?string, since?: ?string, detail?: ?string} $check
     *
     * @return array{status: string, tone: string, label: string, title: string, text: string, area: string, key: ?string, detail: ?string, since: ?string, fix: ?string}
     */
    private static function row(array $check, bool $fixes): array
    {
        $status = strtolower($check['status']);
        $key = $check['key'] ?? null;
        $since = $check['since'] ?? null;

        return [
            'status' => $check['status'],
            'tone' => $status === 'pass' ? 'success' : (str_contains($status, 'fail') || str_contains($status, 'err') ? 'error' : 'warning'),
            'label' => 'larena-env::admin.doctor.'.$status,
            'title' => $check['title'],
            'text' => SiteDoctor::checkText($check['title'], $key),
            'area' => SiteDoctor::areaText($check['area']),
            'key' => $key,
            'detail' => $check['detail'] ?? null,
            'since' => $since === null ? null : Moment::human($since),
            // Offered only where the server would take it: it lists the command, names a fix, and the rule does not pass.
            'fix' => $fixes && $status !== 'pass' ? ($check['fix'] ?? null) : null,
        ];
    }
}
