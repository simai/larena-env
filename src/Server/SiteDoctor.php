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
}
