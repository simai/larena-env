<?php

declare(strict_types=1);

namespace Larena\Env\Security;

/**
 * The server's security checks as the panel counts and lists them, on «Безопасность» and among the overview's
 * recommended actions alike, so both say the same «Срочно» (critics of 0.4.1: the overview said 3, the security page 2,
 * one grouping sites and the other not):
 *
 *  - the same finding on several sites is one finding with those sites under it;
 *  - HTTPS and certificate checks of a local name (.test, .localhost) are left out: no authority issues a certificate
 *    for it, so it is said once in a quiet line instead;
 *  - the checks of the panel's own address belong with who may open the panel, not among the sites.
 *
 * The server's own summary counts every site's check and is not shown.
 */
final readonly class SecurityFindings
{
    public const string NOW = 'now';
    public const string TODO = 'todo';
    public const string DONE = 'done';
    public const string UNKNOWN = 'unknown';

    /** The checks that only make sense for a name the internet can reach. */
    public const array PUBLIC_ONLY = ['https', 'certificate'];

    /** The checks about who reaches the panel, said with the checks of the panel's own address. */
    public const array PANEL = ['panel_access', 'panel_domain'];

    /**
     * @param list<array{key: string, check: string, state: string, panel: bool, checks: list<SecurityCheck>, sites: list<string>}> $findings
     *        problems first in the server's order, then the rest
     * @param list<string> $local the local names whose HTTPS checks were left out
     * @param array<string, true> $kinds every kind of check the server made, the left-out ones too
     */
    public function __construct(public array $findings, public array $local, public array $kinds)
    {
    }

    /** Whether the name is one no certificate authority issues for: a reserved or local top-level name. */
    public static function local(string $domain): bool
    {
        return preg_match('/(^|\.)(test|localhost|local|example|invalid|internal|lan|home\.arpa)$/i', $domain) === 1;
    }

    /** @param list<string> $panelHosts the addresses the panel itself answers on */
    public static function of(SecurityStatus $status, array $panelHosts = []): self
    {
        $panelHosts = array_map('strtolower', $panelHosts);
        $findings = [];
        $local = [];
        $kinds = [];
        foreach ($status->checks as $check) {
            $kinds[$check->check] = true;
            if (in_array($check->check, self::PUBLIC_ONLY, true) && $check->domain !== null && self::local($check->domain)) {
                $local[$check->domain] = true;
                continue;
            }
            $panel = in_array($check->check, self::PANEL, true) || ($check->domain !== null && in_array($check->domain, $panelHosts, true));
            $state = self::state($check->status);
            // A problem groups with the same problem on other sites whether it is a risk or a warning; a fine check with
            // the same fine ones. The panel's own address stands apart from the sites.
            $key = ($check->domain === null ? $check->id : ($panel ? 'panel-' : '').$check->check).($check->isProblem() ? '' : '-'.$state);
            if (!isset($findings[$key])) {
                $findings[$key] = ['key' => $key, 'check' => $check->check, 'state' => $state, 'panel' => $panel, 'checks' => [], 'sites' => []];
            }
            $findings[$key]['checks'][] = $check;
            if ($check->domain !== null) {
                $findings[$key]['sites'][] = $check->domain;
            }
            // The worst of a group's problems says how urgent it is.
            if ($state === self::NOW) {
                $findings[$key]['state'] = self::NOW;
            }
        }
        $local = array_keys($local);
        sort($local);

        return new self(array_values($findings), $local, $kinds);
    }

    /** The panel's word for a check's status: a risk is urgent, a warning advised, the scale of «Рекомендуемые действия». */
    public static function state(string $status): string
    {
        return match ($status) {
            'risk' => self::NOW,
            'warn' => self::TODO,
            'ok' => self::DONE,
            default => self::UNKNOWN,
        };
    }

    /** @return list<array{key: string, check: string, state: string, panel: bool, checks: list<SecurityCheck>, sites: list<string>}> */
    public function problems(?bool $panel = null): array
    {
        return array_values(array_filter($this->findings, static fn (array $finding): bool => in_array($finding['state'], [self::NOW, self::TODO], true)
            && ($panel === null || $finding['panel'] === $panel)));
    }

    /** @return list<array{key: string, check: string, state: string, panel: bool, checks: list<SecurityCheck>, sites: list<string>}> */
    public function rest(): array
    {
        return array_values(array_filter($this->findings, static fn (array $finding): bool => !in_array($finding['state'], [self::NOW, self::TODO], true)));
    }

    public function count(string $state): int
    {
        return count(array_filter($this->findings, static fn (array $finding): bool => $finding['state'] === $state));
    }

    /** @return array{now: int, todo: int, done: int} */
    public function counts(): array
    {
        return ['now' => $this->count(self::NOW), 'todo' => $this->count(self::TODO), 'done' => $this->count(self::DONE)];
    }
}
