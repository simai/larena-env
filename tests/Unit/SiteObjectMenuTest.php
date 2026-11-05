<?php

declare(strict_types=1);

// A site's sections in the menu's second column (owner decision 2026-11-05, simai.admin-navigation-port 1.1.0 `context`):
// only a site's page has one; it names the site, what it runs and whether it works, leads back to all sites, lists the
// sections the server can show, marks the open one, and the admin's cleaner keeps it whole.

use Illuminate\Http\Request;
use Larena\Admin\Navigation\AdminObjectMenus;
use Larena\Env\Admin\SiteObjectMenu;
use Larena\Env\Gateway\PanelActor;
use Larena\Env\Gateway\RecordedAnswersGateway;
use Larena\Env\Http\Middleware\ResolvePanelActor;
use Larena\Env\Server\ServerOverviewReader;
use Larena\Env\Server\SiteDetailsReader;
use Larena\Env\Server\SiteExtrasReader;
use Larena\Env\Warnings\PanelWarningSource;

require_once dirname(__DIR__, 2).'/vendor/autoload.php';
require_once dirname(__DIR__).'/Support/env-test.php';

$translator = new class (require dirname(__DIR__, 2).'/resources/lang/en/admin.php') implements \Illuminate\Contracts\Translation\Translator {
    /** @param array<string, mixed> $lines */
    public function __construct(private array $lines) {}

    public function get($key, array $replace = [], $locale = null): mixed
    {
        $value = data_get($this->lines, substr($key, strlen('larena-env::admin.')));
        if (!is_string($value)) {
            return $key;
        }
        foreach ($replace as $name => $with) {
            $value = str_replace(':'.$name, (string) $with, $value);
        }

        return $value;
    }

    public function choice($key, $number, array $replace = [], $locale = null): string
    {
        return (string) $this->get($key, $replace);
    }

    public function getLocale(): string
    {
        return 'en';
    }

    public function setLocale($locale): void
    {
    }
};
$gateway = new RecordedAnswersGateway(env_recorded_path());
$resolver = (new ReflectionClass(\Larena\Env\Http\Support\PanelActorResolver::class))->newInstanceWithoutConstructor();
$route = static fn (string $name, array $parameters): string => match ($name) {
    'larena.env.admin.site' => '/admin/server/sites/'.rawurlencode($parameters['domain']),
    'larena.env.admin.index' => '/admin/server',
    default => throw new RuntimeException($name),
};
$source = new SiteObjectMenu($resolver, new SiteDetailsReader($gateway), new ServerOverviewReader($gateway), new PanelWarningSource(new SiteExtrasReader($gateway)), $translator, $route);
$request = Request::create('/admin/server/port');
$request->attributes->set(ResolvePanelActor::ATTRIBUTE, new PanelActor('admin@example.com'));

foreach (['/admin/server', '/admin/server/sites/new', '/admin/server/overview', '/admin/people', '/admin/server/sites/'] as $page) {
    env_assert($source->objectMenu($request, $page) === null, 'Not a site\'s page: '.$page);
}

$menu = $source->objectMenu($request, '/admin/server/sites/demo-php.test?tab=backups');
env_assert($menu !== null && $menu['title'] === 'demo-php.test' && $menu['subtitle'] === 'PHP 8.3', 'The site and what it runs: '.json_encode($menu));
env_assert($menu['back'] === ['label' => 'All sites', 'href' => '/admin/server'] && in_array($menu['status']['status'], ['success', 'info', 'error'], true), 'Back to all sites, and its state.');
$keys = array_column($menu['groups'][0]['items'], 'key');
env_assert($keys[0] === 'site.overview' && in_array('site.backups', $keys, true) && !in_array('site.dns', $keys, true) && $menu['current'] === 'site.backups', 'Its sections, the open one: '.implode(',', $keys));
$first = $menu['groups'][0]['items'][0];
env_assert($first['href'] === '/admin/server/sites/demo-php.test' && $first['icon'] === 'dashboard' && $first['label'] === 'Overview'
    && $menu['groups'][0]['items'][1]['href'] === '/admin/server/sites/demo-php.test?tab='.substr($keys[1], 5), 'Each section is a link of the site.');
// A part shown inside another section opens that section.
env_assert($source->objectMenu($request, '/admin/server/sites/demo-php.test?tab=dns')['current'] === 'site.domains'
    && $source->objectMenu($request, '/admin/server/sites/demo-php.test?tab=nope')['current'] === 'site.overview', 'An old or unknown tab.');
// An unknown site has no menu: its page says why.
env_assert($source->objectMenu($request, '/admin/server/sites/missing.test') === null, 'No menu for a site the server does not have.');
// The admin keeps the answer as it is.
env_assert(AdminObjectMenus::clean($menu) === $menu, 'The answer passes the admin\'s cleaner unchanged.');

echo "SiteObjectMenuTest passed.\n";
