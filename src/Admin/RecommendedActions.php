<?php

declare(strict_types=1);

namespace Larena\Env\Admin;

use Larena\Env\Security\SecurityFindings;
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

    /** Whether the name is one no certificate authority issues for (SecurityFindings::local, kept for the sites' list). */
    public static function local(string $domain): bool
    {
        return SecurityFindings::local($domain);
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

    /**
     * The security findings are SecurityFindings', the ones «Безопасность» lists and counts, so the two pages say the
     * same «Срочно» for the same thing; the overview adds the waiting update and the setup steps left, and says so.
     *
     * @param array<string, array{title: string, line: string, how: string}> $words the security checks in the panel's words
     * @param array<string, bool> $fixes check id => fixable here by one click
     * @param callable(string): string $siteUrl
     * @param array<string, string> $setup the setup steps' states
     * @param array<string, ?string> $setupLinks each step's screen
     * @param list<array{target: string, available: ?string}> $updates
     * @param callable(string, array<string, string>=): string $text
     * @param ?string $panelUrl «Кто может открыть панель», where the checks of the panel's own address lead
     *
     * @return list<array{key: string, state: string, title: string, line: string, action: ?array{kind: string, url: string, label: string, fields?: array<string, string>}, sites?: list<string>}>
     */
    public static function of(?SecurityFindings $security, array $words, array $fixes, string $securityUrl, string $fixUrl, callable $siteUrl,
        array $setup, array $setupLinks, array $updates, string $updateUrl, callable $text, ?string $panelUrl = null): array
    {
        $items = [];
        foreach ($security->findings ?? [] as $finding) {
            if ($finding['state'] === SecurityFindings::UNKNOWN) {
                continue;
            }
            $first = $finding['checks'][0];
            $word = $words[$first->id] ?? ['title' => $first->title ?? $first->check, 'line' => $first->detail ?? '', 'how' => ''];
            $sites = $finding['sites'];
            $title = $word['title'].match (count($sites)) {
                0 => '',
                1 => ' · '.$sites[0],
                default => ' · '.$text('actions.sites', ['count' => (string) count($sites)]),
            };
            $anchor = $securityUrl.'#env-security-check-'.$finding['key'];
            $action = null;
            if ($finding['state'] !== SecurityFindings::DONE) {
                $action = match (true) {
                    count($finding['checks']) === 1 && ($fixes[$first->id] ?? false) => ['kind' => 'form', 'url' => $fixUrl, 'label' => $text('actions.fix'),
                        'fields' => ['op' => 'fix', 'check' => $first->id]],
                    // The panel's own address is set where who may open the panel is (critics of 0.4.1).
                    $finding['panel'] && $sites !== [] => ['kind' => 'link', 'url' => $panelUrl ?? $anchor, 'label' => $text('actions.open')],
                    count($sites) === 1 => ['kind' => 'link', 'url' => $siteUrl($sites[0]), 'label' => $text('actions.open')],
                    default => ['kind' => 'link', 'url' => $anchor, 'label' => $text('actions.open')],
                };
            }
            $items[] = ['key' => 'security:'.$finding['key'], 'state' => $finding['state'], 'title' => $title, 'line' => $word['line'], 'action' => $action, 'sites' => $sites];
        }
        foreach ($updates as $update) {
            $items[] = ['key' => 'update:'.$update['target'], 'state' => self::TODO, 'title' => $text('update.hint.'.$update['target'], ['available' => $update['available'] ?? '—']),
                'line' => $text('actions.update_line'), 'action' => ['kind' => 'link', 'url' => $updateUrl.(str_contains($updateUrl, '?') ? '&' : '?').'target='.$update['target'], 'label' => $text('actions.update')]];
        }
        foreach ($setup as $step => $state) {
            if (isset(self::COVERED[$step], $security?->kinds[self::COVERED[$step]])) {
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

    /**
     * How many urgent and advised items the overview shows, and how many of them are the security page's: the rest are
     * the setup steps left and the waiting update, which the overview says in words so its «Срочно» never looks like a
     * different count of the same thing.
     *
     * @param list<array{key: string, state: string}> $items
     * @return array{now: int, todo: int, done: int, security_now: int, security_todo: int, other: bool}
     */
    public static function counts(array $items): array
    {
        $security = array_values(array_filter($items, static fn (array $item): bool => str_starts_with($item['key'], 'security:')));
        $counts = ['now' => self::count($items, self::NOW), 'todo' => self::count($items, self::TODO), 'done' => self::count($items, self::DONE),
            'security_now' => self::count($security, self::NOW), 'security_todo' => self::count($security, self::TODO)];

        return $counts + ['other' => $counts['now'] + $counts['todo'] > $counts['security_now'] + $counts['security_todo']];
    }
}
