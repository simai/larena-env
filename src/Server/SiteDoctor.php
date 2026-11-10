<?php

declare(strict_types=1);

namespace Larena\Env\Server;

use Larena\Env\Gateway\PanelCommands;
use UnexpectedValueException;

/**
 * What simai-env's check of a site found (simai-site-diagnostics/1): its overall state and the checks that did not
 * pass, which the site's overview names as its problems. Passed and skipped checks are counted, not listed.
 *
 * Since simai-env 1.55.1 a rule of the site's profile carries its own `key`, what the server found (`detail`), since
 * when it does not pass (`since`, null while it passes) and the id of the fix the server offers for it (`fix`, or null).
 * The rules that pass are kept too, so the overview can list them; a check of an older server has none of this.
 */
final readonly class SiteDoctor
{
    private const string SCHEMA = 'simai-site-diagnostics/1';

    /**
     * @param list<array{status: string, area: string, title: string, key: ?string, fix: ?string, since: ?string, detail: ?string}> $problems
     * @param list<array{status: string, area: string, title: string, key: ?string, fix: ?string, since: ?string, detail: ?string}> $passedRules the profile's rules that pass
     */
    public function __construct(public string $status, public array $problems, public int $passed, public array $passedRules = [])
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
        $passedRules = [];
        $passed = 0;
        foreach ($data['checks'] as $check) {
            if (!is_array($check) || !is_string($check['status'] ?? null) || !is_string($check['title'] ?? null)) {
                throw new UnexpectedValueException('site_doctor_shape_invalid');
            }
            $text = static fn (string $name): ?string => is_string($check[$name] ?? null) && trim($check[$name]) !== '' ? $check[$name] : null;
            $key = $text('key');
            $fix = $text('fix');
            $row = ['status' => $check['status'], 'area' => is_string($check['area'] ?? null) ? $check['area'] : '', 'title' => $check['title'],
                'key' => $key,
                // A fix is one of a rule, by an id the gateway would take: anything else is no fix, never a button that fails.
                'fix' => $key !== null && $fix !== null && PanelCommands::isCheckId($fix) ? $fix : null,
                'since' => $key === null ? null : $text('since'),
                'detail' => $key === null ? null : $text('detail')];
            if ($check['status'] === 'PASS') {
                $passed++;
                if ($key !== null) {
                    $passedRules[] = $row;
                }
            } elseif (in_array($check['status'], ['WARN', 'FAIL'], true)) {
                $problems[] = $row;
            }
        }

        return new self($data['status'], $problems, $passed, $passedRules);
    }

    /**
     * A check of the server's, as a person reads it: in the person's language where the panel knows it (a profile's rule
     * by its key, any check by its title), else as the server wrote it.
     */
    public static function checkText(string $title, ?string $key = null): string
    {
        $slug = static fn (string $text): string => strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '_', $text), '_'));

        return self::word('rules', $key === null ? '' : $slug($key), self::word('checks', $slug($title), $title));
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
