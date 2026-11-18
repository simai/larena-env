<?php

declare(strict_types=1);

namespace Larena\Env\Security;

/**
 * «Безопасность» as a list of things to do (study part 8, PX-83; Framework C37, sf-list-attention in its group mode):
 * what is urgent, what is advised, what is done, each finding once — the same check on several sites is one row that
 * names how many sites it touches, never a row per site. A row's action is the next step: the server's own fix through
 * its plan, the sites the fix is done on, the site's card, or for the panel's own address «Кто может открыть панель»
 * among the settings (the duplicate of that section is a link now, PX-81).
 */
final readonly class SecurityTodo
{
    /**
     * @param array<string, array{title: string, line: string, how: string}> $words the panel's words of each check
     * @param array<string, bool> $fixes whether the server's own fix of each check may be run from here
     * @param \Closure(string, array<string, string>=): string $text the panel's line for a key under larena-env::admin.
     * @param \Closure(string, array<string, string>=): string $route a panel route's address by name
     *
     * @return array{items: list<array<string, mixed>>, passed: list<array{id: string, title: string, text: string}>}
     */
    public static function of(SecurityFindings $findings, array $words, array $fixes, \Closure $text, \Closure $route): array
    {
        $items = [];
        foreach ($findings->problems() as $finding) {
            $first = $finding['checks'][0];
            $word = $words[$first->id] ?? ['title' => $first->check, 'line' => '', 'how' => ''];
            $sites = $finding['sites'];
            $fixable = array_filter($finding['checks'], static fn (SecurityCheck $check): bool => $fixes[$check->id] ?? false);
            $item = [
                'id' => $finding['key'],
                'status' => $finding['state'] === SecurityFindings::NOW ? 'error' : 'warning',
                'title' => $word['title'],
                'text' => $word['line'],
            ];
            if ($finding['panel']) {
                // The checks of the panel's own address are said as the panel's, the page's own need no source.
                $item['source'] = $text('panel_access.cards.address');
            }
            if (count($sites) === 1) {
                $item['object'] = $finding['panel'] ? ['label' => $sites[0]] : ['label' => $sites[0], 'href' => $route('larena.env.admin.site', ['domain' => $sites[0]])];
                $item['affected'] = $text('security.todo.one_site');
            } elseif (count($sites) > 1) {
                $item['affected'] = $text('actions.sites', ['count' => (string) count($sites)]);
            } else {
                $item['affected'] = $text('security.todo.server');
            }
            if ($fixable !== [] && count($finding['checks']) === 1) {
                // One check the server puts right itself: its plan, shown by a link (the run is still confirmed).
                $item['action'] = ['label' => $text('security.todo.fix'), 'href' => $route('larena.env.admin.security.plan.open', ['op' => 'fix', 'check' => $first->id])];
            } elseif ($fixable !== []) {
                // The same fix on several sites: the sites with their own «Исправить», under the list, on demand.
                $item['action'] = ['label' => $text('security.todo.fix_sites'), 'href' => $route('larena.env.admin.security', ['finding' => $finding['key']]).'#env-security-sites'];
            } elseif ($first->check === 'site_components') {
                // The packages and their fixed versions are listed on this page, under «Защита».
                $item['action'] = ['label' => $text('security.protection.show'), 'href' => $route('larena.env.admin.security', ['edit' => 'vulnerabilities']).'#env-security-vulnerabilities'];
                $item['text'] = trim($word['line'].' '.$word['how']);
            } elseif ($finding['panel']) {
                $item['action'] = ['label' => $text('security.todo.open_settings'), 'href' => $route('larena.env.admin.panel.access')];
                $item['text'] = trim($word['line'].' '.$word['how']);
            } elseif (count($sites) === 1) {
                $item['action'] = ['label' => $text('security.open_site'), 'href' => $route('larena.env.admin.site', ['domain' => $sites[0]])
                    .($first->check === 'https' ? '?tab=domains' : ($first->check === 'backup_verify' ? '?tab=backups' : ''))];
                $item['text'] = trim($word['line'].' '.$word['how']);
            } else {
                $item['text'] = trim($word['line'].' '.$word['how']);
            }
            $items[] = $item;
        }
        $passed = [];
        foreach ($findings->rest() as $finding) {
            $first = $finding['checks'][0];
            $word = $words[$first->id] ?? ['title' => $first->check, 'line' => '', 'how' => ''];
            $passed[] = ['id' => $finding['key'], 'title' => $word['title'].(count($finding['sites']) > 1 ? ' · '.$text('actions.sites', ['count' => (string) count($finding['sites'])]) : ''), 'text' => $word['line']];
        }

        return ['items' => $items, 'passed' => $passed];
    }
}
