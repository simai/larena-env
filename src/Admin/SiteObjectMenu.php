<?php

declare(strict_types=1);

namespace Larena\Env\Admin;

use Closure;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Http\Request;
use Larena\Admin\Contracts\AdminObjectMenuSource;
use Larena\Env\Gateway\PanelActor;
use Larena\Env\Gateway\PanelCapabilities;
use Larena\Env\Gateway\PanelRefused;
use Larena\Env\Http\Controllers\SiteAdminController;
use Larena\Env\Http\Middleware\ResolvePanelActor;
use Larena\Env\Http\Support\PanelActorResolver;
use Larena\Env\Server\ServerOverviewReader;
use Larena\Env\Server\SiteDetailsReader;
use Larena\Env\Warnings\PanelWarnings;
use Larena\Env\Warnings\PanelWarningSource;

/**
 * A site's sections in the menu's second column (owner decision 2026-11-05: never more than two menus, the second
 * follows the open object; simai.admin-navigation-port 1.1.0 `context`). It answers for a site's page only: the site's
 * name, what it runs, whether it works, «← Все сайты», its sections with a mark where something needs attention, and the
 * open one. The answers come from the gateway's short cache, which the page itself has just filled.
 */
final readonly class SiteObjectMenu implements AdminObjectMenuSource
{
    /** Each section's icon in the menu. */
    public const array ICONS = [
        'overview' => 'dashboard', 'domains' => 'language', 'php' => 'code', 'files' => 'folder', 'database' => 'database',
        'backups' => 'backup', 'cron' => 'schedule', 'access' => 'key', 'log' => 'receipt_long', 'releases' => 'deployed_code',
    ];

    /**
     * @param Closure(string, array<string, string>): string $route a route's path by name and parameters
     */
    public function __construct(
        private PanelActorResolver $actors,
        private SiteDetailsReader $sites,
        private ServerOverviewReader $overviews,
        private PanelWarningSource $warnings,
        private Translator $translator,
        private Closure $route,
    ) {
    }

    public function objectMenu(Request $request, string $page): ?array
    {
        $domain = $this->domainOf($page);
        if ($domain === null) {
            return null;
        }
        $actor = $request->attributes->get(ResolvePanelActor::ATTRIBUTE);
        if (!$actor instanceof PanelActor) {
            try {
                $actor = $this->actors->resolve($request);
            } catch (PanelRefused) {
                return null;
            }
        }
        $site = $this->sites->read($actor, $domain)->site;
        if ($site === null) {
            return null;
        }
        $overview = $this->overviews->read($actor)->overview;
        $capabilities = $overview->capabilities ?? PanelCapabilities::none();
        $sections = SiteAdminController::sectionsOf($site, $capabilities);
        $warnings = $overview === null ? null : $this->warnings->ofSite($actor, $overview, $domain, PanelWarnings::now())['warnings'];
        $text = fn (string $key): string => (string) $this->translator->get('larena-env::admin.'.$key);
        $href = ($this->route)('larena.env.admin.site', ['domain' => $domain]);
        $items = [];
        foreach (SiteCardSections::of($sections, $warnings) as $group) {
            foreach ($group['items'] as $item) {
                $row = ['key' => 'site.'.$item['name'], 'label' => $text('site.tabs.'.$item['name']),
                    'href' => $item['name'] === 'overview' ? $href : $href.'?tab='.$item['name'], 'icon' => self::ICONS[$item['name']] ?? 'circle'];
                if ($item['attention'] !== null) {
                    $row['badge'] = ['text' => $text('warnings.severity.'.$item['attention']), 'status' => $item['attention'] === 'error' ? 'error' : 'warning'];
                }
                $items[] = $row;
            }
        }
        $state = SiteState::of($site->answersChecked, $site->answers);
        $query = [];
        parse_str((string) parse_url($page, PHP_URL_QUERY), $query);
        $open = SiteAdminController::section($query['tab'] ?? null);

        return [
            'title' => $domain,
            'subtitle' => $site->php !== 'none' ? 'PHP '.$site->php : $text('sites.kinds.'.$site->profile),
            'status' => ['text' => $text('sites.state.'.$state), 'status' => SiteState::status($state)],
            'back' => ['label' => $text('site.all_sites'), 'href' => ($this->route)('larena.env.admin.index', [])],
            'groups' => [['label' => null, 'items' => $items]],
            'current' => 'site.'.(in_array($open, $sections, true) ? $open : 'overview'),
        ];
    }

    /** The site a page shows, or null for any other page (the new site's form is not a site). */
    private function domainOf(string $page): ?string
    {
        $marker = 'larena-env-site-marker';
        $pattern = ($this->route)('larena.env.admin.site', ['domain' => $marker]);
        $at = strpos($pattern, $marker);
        if ($at === false) {
            return null;
        }
        $path = (string) parse_url($page, PHP_URL_PATH);
        $prefix = substr($pattern, 0, $at);
        if (!str_starts_with($path, $prefix)) {
            return null;
        }
        $domain = rawurldecode(substr($path, strlen($prefix)));

        return preg_match('/\A[a-z0-9](?:[a-z0-9.-]{0,251}[a-z0-9])?\z/i', $domain) === 1 && str_contains($domain, '.') ? strtolower($domain) : null;
    }
}
