<?php

declare(strict_types=1);

namespace Larena\Env\Admin;

use Larena\Env\Security\SecurityCheck;
use Larena\Env\Security\SecurityStatus;
use Larena\Env\Setup\ServerSetup;

/**
 * «Рекомендуемые действия» on the overview (owner 2026-11-06, through ENV): one list of what to do now, what is
 * advised and what is done, each with its one-click action that goes through the usual plan and confirmation.
 * The server's own security checks come first; a setup step the security checks already cover is not said twice.
 */
final class RecommendedActions
{
    public const string NOW = 'now';
    public const string TODO = 'todo';
    public const string DONE = 'done';

    /** The setup step the server's check of this kind already says. */
    private const array COVERED = ['backups' => 'backup_storage', 'updates' => 'auto_updates', 'address' => 'panel_domain'];

    /**
     * @param array<string, array{title: string, line: string, how: string}> $words the security checks in the panel's words
     * @param array<string, bool> $fixes check id => fixable here by one click
     * @param array<string, string> $setup the setup steps' states
     * @param array<string, ?string> $setupLinks each step's screen
     * @param list<array{target: string, available: ?string}> $updates
     * @param callable(string, array<string, string>=): string $text
     *
     * @return list<array{key: string, state: string, title: string, line: string, action: ?array{kind: string, url: string, label: string, fields?: array<string, string>}}>
     */
    /** The checks that only make sense for a name the internet can reach. */
    private const array PUBLIC_ONLY = ['https', 'certificate'];

    /** Whether the name is one no certificate authority issues for: a reserved or local top-level name. */
    public static function local(string $domain): bool
    {
        return preg_match('/(^|\.)(test|localhost|local|example|invalid|internal|lan|home\.arpa)$/i', $domain) === 1;
    }

    /** How many advised items the overview shows before folding the rest. */
    public const int ADVISED_IN_VIEW = 5;

    /**
     * What the overview shows unfolded: every urgent item and the first advised ones (critics 2026-11-07).
     *
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    public static function inView(array $items): array
    {
        return array_merge(self::inState($items, self::NOW), array_slice(self::inState($items, self::TODO), 0, self::ADVISED_IN_VIEW));
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    public static function folded(array $items): array
    {
        return array_slice(self::inState($items, self::TODO), self::ADVISED_IN_VIEW);
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private static function inState(array $items, string $state): array
    {
        return array_values(array_filter($items, static fn (array $item): bool => ($item['state'] ?? null) === $state));
    }

    public static function of(?SecurityStatus $security, array $words, array $fixes, string $securityUrl, string $fixUrl, callable $siteUrl,
        array $setup, array $setupLinks, array $updates, string $updateUrl, callable $text): array
    {
        $items = [];
        $kinds = [];
        $grouped = [];
        foreach ($security->checks ?? [] as $check) {
            $kinds[$check->check] = true;
            // A local name (.test, .localhost) gets no public certificate: HTTPS is not advised for it (critics 2026-11-07).
            if (in_array($check->check, self::PUBLIC_ONLY, true) && $check->domain !== null && self::local($check->domain)) {
                continue;
            }
            $word = $words[$check->id] ?? ['title' => $check->title ?? $check->check, 'line' => $check->detail ?? '', 'how' => ''];
            $title = $word['title'].($check->domain !== null ? ' · '.$check->domain : '');
            if (!$check->isProblem()) {
                if ($check->status === 'ok') {
                    $items[] = ['key' => 'security:'.$check->id, 'state' => self::DONE, 'title' => $title, 'line' => $word['line'], 'action' => null];
                }
                continue;
            }
            $action = ($fixes[$check->id] ?? false)
                ? ['kind' => 'form', 'url' => $fixUrl, 'label' => $text('actions.fix'), 'fields' => ['op' => 'fix', 'check' => $check->id]]
                : ['kind' => 'link', 'url' => $check->domain !== null ? $siteUrl($check->domain) : $securityUrl.'#env-security-check-'.$check->id, 'label' => $text('actions.open')];
            // The same scale as the security page: its «risk» is urgent, its «warn» advised (critics 2026-11-07).
            $state = $check->status === 'risk' ? self::NOW : self::TODO;
            // The same finding on several sites is one line with their count, leading to the security page (critics 2026-11-07).
            if ($check->domain !== null) {
                $same = 'security:'.$check->check.':'.$state;
                if (isset($grouped[$same])) {
                    $items[$grouped[$same]]['sites'][] = $check->domain;
                    $items[$grouped[$same]]['title'] = $word['title'].' · '.$text('actions.sites', ['count' => (string) count($items[$grouped[$same]]['sites'])]);
                    $items[$grouped[$same]]['action'] = ['kind' => 'link', 'url' => $securityUrl.'#env-security-check-'.$check->check, 'label' => $text('actions.open')];
                    continue;
                }
                $grouped[$same] = count($items);
            }
            $items[] = ['key' => 'security:'.$check->id, 'state' => $state, 'title' => $title, 'line' => $word['line'], 'action' => $action,
                'sites' => $check->domain === null ? [] : [$check->domain]];
        }
        foreach ($updates as $update) {
            $items[] = ['key' => 'update:'.$update['target'], 'state' => self::TODO, 'title' => $text('update.hint.'.$update['target'], ['available' => $update['available'] ?? '—']),
                'line' => $text('actions.update_line'), 'action' => ['kind' => 'link', 'url' => $updateUrl.(str_contains($updateUrl, '?') ? '&' : '?').'target='.$update['target'], 'label' => $text('actions.update')]];
        }
        foreach ($setup as $step => $state) {
            if (isset(self::COVERED[$step], $kinds[self::COVERED[$step]])) {
                continue;
            }
            $link = $setupLinks[$step] ?? null;
            // Alerts nobody receives are urgent: without them the owner learns of a failure from the visitors (critics 2026-11-07).
            $items[] = ['key' => 'setup:'.$step, 'state' => $state === ServerSetup::DONE ? self::DONE : ($step === 'alerts' ? self::NOW : self::TODO), 'title' => $text('setup.step.'.$step.'.name'),
                'line' => $text('setup.step.'.$step.'.why'),
                'action' => $state === ServerSetup::DONE || $link === null ? null : ['kind' => 'link', 'url' => $link, 'label' => $text('setup.step.'.$step.'.open')]];
        }
        $order = [self::NOW => 0, self::TODO => 1, self::DONE => 2];
        uksort($items, static fn (int $a, int $b): int => [$order[$items[$a]['state']], $a] <=> [$order[$items[$b]['state']], $b]);

        return array_values($items);
    }

    /** @param list<array{state: string}> $items */
    public static function count(array $items, string $state): int
    {
        return count(array_filter($items, static fn (array $item): bool => $item['state'] === $state));
    }
}
