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
    public static function of(?SecurityStatus $security, array $words, array $fixes, string $securityUrl, string $fixUrl, callable $siteUrl,
        array $setup, array $setupLinks, array $updates, string $updateUrl, callable $text): array
    {
        $items = [];
        $kinds = [];
        foreach ($security->checks ?? [] as $check) {
            $kinds[$check->check] = true;
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
            $items[] = ['key' => 'security:'.$check->id, 'state' => in_array($check->severity, ['high', 'critical'], true) ? self::NOW : self::TODO,
                'title' => $title, 'line' => $word['line'], 'action' => $action];
        }
        foreach ($updates as $update) {
            $items[] = ['key' => 'update:'.$update['target'], 'state' => self::TODO, 'title' => $text('update.hint.'.$update['target'], ['available' => $update['available'] ?? '—']),
                'line' => $text('actions.update_line'), 'action' => ['kind' => 'link', 'url' => $updateUrl, 'label' => $text('actions.update')]];
        }
        foreach ($setup as $step => $state) {
            if (isset(self::COVERED[$step], $kinds[self::COVERED[$step]])) {
                continue;
            }
            $link = $setupLinks[$step] ?? null;
            $items[] = ['key' => 'setup:'.$step, 'state' => $state === ServerSetup::DONE ? self::DONE : self::TODO, 'title' => $text('setup.step.'.$step.'.name'),
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
