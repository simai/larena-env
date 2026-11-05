<?php

declare(strict_types=1);

// The panel's warnings in the admin's alert bar (owner decision 2026-11-05): the recorded server's alerts as the bar's
// items, danger for its errors, each with the place it is dealt with; a site's owner sees their sites only.

use Illuminate\Http\Request;
use Larena\Admin\Alerts\AdminAlert;
use Larena\Admin\Alerts\AdminAlertLevel;
use Larena\Env\Gateway\PanelActor;
use Larena\Env\Gateway\RecordedAnswersGateway;
use Larena\Env\Http\Middleware\ResolvePanelActor;
use Larena\Env\Server\ServerOverviewReader;
use Larena\Env\Server\SiteExtrasReader;
use Larena\Env\Warnings\EnvAdminAlertSource;
use Larena\Env\Warnings\PanelWarning;

require_once dirname(__DIR__, 2).'/vendor/autoload.php';
require_once dirname(__DIR__).'/Support/env-test.php';

/** @param array<string, string> $parameters */
function route(string $name, array $parameters = [], bool $absolute = true): string
{
    return match ($name) {
        'larena.env.admin.site' => '/admin/server/sites/'.$parameters['domain'],
        'larena.env.admin.overview' => '/admin/server/overview',
        'larena.env.admin.alerts.ack.open' => '/admin/server/alerts/ack/plan',
        default => throw new RuntimeException($name),
    };
}

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
$asked = 0;
$cached = [];
$remember = static function (string $key, int $ttl, Closure $read) use (&$asked, &$cached): array {
    $asked++;

    return $cached[$key] ??= $read();
};
$resolver = (new ReflectionClass(\Larena\Env\Http\Support\PanelActorResolver::class))->newInstanceWithoutConstructor();
$source = new EnvAdminAlertSource($resolver, new ServerOverviewReader($gateway), new SiteExtrasReader($gateway), $translator, null, $remember, ['panel.localhost']);
$as = static function (PanelActor $actor): Request {
    $request = Request::create('/admin/server');
    $request->attributes->set(ResolvePanelActor::ATTRIBUTE, $actor);

    return $request;
};

$admin = $source->alerts($as(new PanelActor('admin@example.com')));
env_assert($admin !== [], 'The recorded server has warnings.');
foreach ($admin as $alert) {
    env_assert(str_starts_with($alert->id, 'env:') && $alert->action !== null
        && str_starts_with($alert->action->href, '/admin/server/') && $alert->text !== '' && !str_contains($alert->text, 'larena-env::'), 'An item: '.$alert->id);
    // Without the right to change the server, no acknowledgement is offered.
    env_assert($alert->acknowledge === null, 'No acknowledgement without env.sites.operate.');
}
$levels = array_map(static fn (AdminAlert $alert): string => $alert->level->value, $admin);
env_assert(array_diff($levels, ['danger', 'warning']) === [], 'Errors are danger, warnings are warnings: '.implode(',', $levels));
// The server is asked once per person within the minute.
$source->alerts($as(new PanelActor('admin@example.com')));
env_assert($asked === 2 && count($cached) === 1, 'The answer is reused.');

// A site's owner: their sites only, never the whole server's.
$owner = $source->alerts($as(new PanelActor('staff@example.com', 'demo')));
foreach ($owner as $alert) {
    env_assert(str_contains($alert->id, 'demo-owned.test') || str_starts_with($alert->id, 'env:alert:'), 'Owner sees their own: '.$alert->id);
}

// The condition's name in the bar.
env_assert(EnvAdminAlertSource::id(new PanelWarning('backup_failed', 'warning', 'Demo.Test')) === 'env:backup_failed:demo.test'
    && EnvAdminAlertSource::id(new PanelWarning('disk_alert', 'error', null, alertId: 'disk-root')) === 'env:alert:disk-root'
    && preg_match(AdminAlert::ID, EnvAdminAlertSource::id(new PanelWarning('swap_full', 'error', null, subject: 'Swap Space'))) === 1, 'Ids fit the bar.');
env_assert(AdminAlertLevel::Danger->rank() === 0, 'The admin\'s levels.');

echo "EnvAlertSourceTest passed.\n";
