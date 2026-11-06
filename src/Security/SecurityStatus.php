<?php

declare(strict_types=1);

namespace Larena\Env\Security;

/**
 * The server's security at a glance (security:status, simai-security/1): a score of 0–100 and its grade, the checks the
 * server made with the problems first in the order it recommends, the protection level it applied, the sites' packages
 * with known vulnerabilities, and whether it could ask the vulnerability databases.
 */
final readonly class SecurityStatus
{
    public const string SCHEMA = 'simai-security/1';
    public const array GRADES = ['good', 'attention', 'risk'];
    public const array LEVELS = ['basic', 'enhanced', 'strict'];

    /**
     * @param list<SecurityCheck> $checks problems first, as the server recommends, then the rest
     * @param array<string, list<array{package: string, version: ?string, id: string, severity: ?string, summary: ?string, fixed: ?string}>> $vulnerabilities
     * @param array{risk: int, warn: int, ok: int, unknown: int} $summary
     */
    public function __construct(
        public ?int $score,
        public string $grade,
        public array $summary,
        public array $checks,
        public ?string $level,
        public ?string $levelAppliedAt,
        public array $vulnerabilities,
        public bool $onlineEnabled,
        public ?string $onlineError,
        public ?string $checkedAt,
    ) {
    }

    /** @return list<SecurityCheck> */
    public function problems(): array
    {
        return array_values(array_filter($this->checks, static fn (SecurityCheck $check): bool => $check->isProblem()));
    }

    /** @return list<SecurityCheck> the checks that are fine or not known yet */
    public function rest(): array
    {
        return array_values(array_filter($this->checks, static fn (SecurityCheck $check): bool => !$check->isProblem()));
    }

    public function check(string $id): ?SecurityCheck
    {
        foreach ($this->checks as $check) {
            if ($check->id === $id) {
                return $check;
            }
        }

        return null;
    }

    /** @param array<array-key, mixed> $data */
    public static function fromData(array $data): ?self
    {
        if (($data['schema'] ?? null) !== self::SCHEMA || !is_array($data['checks'] ?? null)) {
            return null;
        }
        $checks = [];
        foreach ($data['checks'] as $item) {
            $check = is_array($item) ? SecurityCheck::fromData($item) : null;
            if ($check !== null) {
                $checks[$check->id] = $check;
            }
        }
        // The problems in the order the server recommends, then the other problems, then the rest as listed.
        $ordered = [];
        foreach (is_array($data['recommendations'] ?? null) ? $data['recommendations'] : [] as $id) {
            if (is_string($id) && isset($checks[$id]) && $checks[$id]->isProblem()) {
                $ordered[$id] = $checks[$id];
            }
        }
        $rank = static fn (SecurityCheck $check): int => $check->status === 'risk' ? 0 : 1;
        $others = array_filter($checks, static fn (SecurityCheck $check): bool => $check->isProblem() && !isset($ordered[$check->id]));
        uasort($others, static fn (SecurityCheck $a, SecurityCheck $b): int => $rank($a) <=> $rank($b));
        $ordered += $others;
        $ordered += $checks;
        $summary = is_array($data['summary'] ?? null) ? $data['summary'] : [];
        $count = static fn (string $key): int => is_int($summary[$key] ?? null) && $summary[$key] >= 0 ? $summary[$key] : 0;
        $level = is_array($data['level'] ?? null) ? $data['level'] : [];
        $online = is_array($data['online'] ?? null) ? $data['online'] : [];
        $score = $data['score'] ?? null;

        return new self(
            score: is_int($score) && $score >= 0 && $score <= 100 ? $score : null,
            grade: in_array($data['grade'] ?? null, self::GRADES, true) ? $data['grade'] : 'attention',
            summary: ['risk' => $count('risk'), 'warn' => $count('warn'), 'ok' => $count('ok'), 'unknown' => $count('unknown')],
            checks: array_values($ordered),
            level: in_array($level['current'] ?? null, self::LEVELS, true) ? $level['current'] : null,
            levelAppliedAt: is_string($level['applied_at'] ?? null) ? $level['applied_at'] : null,
            vulnerabilities: self::vulnerabilities($data['vulnerabilities'] ?? null),
            onlineEnabled: ($online['enabled'] ?? false) === true,
            onlineError: is_string($online['error'] ?? null) && $online['error'] !== '' ? mb_substr($online['error'], 0, 300) : null,
            checkedAt: is_string($data['checked_at'] ?? null) ? $data['checked_at'] : null,
        );
    }

    /** @return array<string, list<array{package: string, version: ?string, id: string, severity: ?string, summary: ?string, fixed: ?string}>> */
    private static function vulnerabilities(mixed $data): array
    {
        $sites = [];
        foreach (is_array($data) ? $data : [] as $domain => $items) {
            if (!is_string($domain) || preg_match('/\A[A-Za-z0-9.-]{1,253}\z/D', $domain) !== 1 || !is_array($items)) {
                continue;
            }
            $list = [];
            foreach ($items as $item) {
                if (!is_array($item) || !is_string($item['package'] ?? null) || !is_string($item['id'] ?? null)) {
                    continue;
                }
                $text = static fn (string $key): ?string => is_string($item[$key] ?? null) && $item[$key] !== '' ? mb_substr($item[$key], 0, 300) : null;
                $list[] = ['package' => mb_substr($item['package'], 0, 200), 'version' => $text('version'), 'id' => mb_substr($item['id'], 0, 64),
                    'severity' => $text('severity'), 'summary' => $text('summary'), 'fixed' => $text('fixed')];
            }
            if ($list !== []) {
                $sites[strtolower($domain)] = $list;
            }
        }

        return $sites;
    }
}
