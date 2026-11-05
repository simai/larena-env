<?php

declare(strict_types=1);

namespace Larena\Env\Server;

use UnexpectedValueException;

/**
 * What simai-env's check of a site found (simai-site-diagnostics/1): its overall state and the checks that did not
 * pass, which the site's overview names as its problems. Passed and skipped checks are counted, not listed.
 */
final readonly class SiteDoctor
{
    private const string SCHEMA = 'simai-site-diagnostics/1';

    /** @param list<array{status: string, area: string, title: string}> $problems */
    public function __construct(public string $status, public array $problems, public int $passed)
    {
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @throws UnexpectedValueException
     */
    public static function fromData(array $data): self
    {
        if (($data['schema'] ?? null) !== self::SCHEMA || !is_string($data['status'] ?? null) || !is_array($data['checks'] ?? null)) {
            throw new UnexpectedValueException('site_doctor_shape_invalid');
        }
        $problems = [];
        $passed = 0;
        foreach ($data['checks'] as $check) {
            if (!is_array($check) || !is_string($check['status'] ?? null) || !is_string($check['title'] ?? null)) {
                throw new UnexpectedValueException('site_doctor_shape_invalid');
            }
            if ($check['status'] === 'PASS') {
                $passed++;
            } elseif (in_array($check['status'], ['WARN', 'FAIL'], true)) {
                $problems[] = ['status' => $check['status'], 'area' => is_string($check['area'] ?? null) ? $check['area'] : '', 'title' => $check['title']];
            }
        }

        return new self($data['status'], $problems, $passed);
    }

    /** A check of the server's, as a person reads it: its title in the person's language where the panel knows it, else as the server wrote it. */
    public static function checkText(string $title): string
    {
        return self::word('checks', strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '_', $title), '_')), $title);
    }

    /** The part of the site a check looks at, in the person's language. */
    public static function areaText(string $area): string
    {
        return self::word('areas', $area, $area);
    }

    private static function word(string $group, string $key, string $otherwise): string
    {
        if ($key === '' || !function_exists('__')) {
            return $otherwise;
        }
        $line = 'larena-env::admin.doctor.'.$group.'.'.$key;
        $text = __($line);

        return $text !== $line ? $text : $otherwise;
    }
}
