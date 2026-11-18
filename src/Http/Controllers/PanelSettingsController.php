<?php

declare(strict_types=1);

namespace Larena\Env\Http\Controllers;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Contracts\View\Factory;
use Illuminate\Http\Request;
use Larena\Env\Dns\DnsService;
use Larena\Env\Gateway\PanelCapabilities;
use Larena\Env\Http\Middleware\ResolvePanelActor;
use Larena\Env\Mail\PanelMail;
use Larena\Env\Security\SecurityFindings;
use Larena\Env\Server\ServerOverviewReader;
use Larena\Env\Server\SiteExtrasReader;

/**
 * «Настройки» (study part 8, PX-81; owner decision dec-a3a50baf4b3d): one menu, and its first page a map of the panel's
 * and the server's settings, each with what it holds now in one line and «Изменить» that opens its section. The values
 * are the server's answers the sections read too (cached for a short while), so the map costs no extra waiting there.
 * The map is the Framework's description list (sf-list-description), a value with one action per row.
 */
final readonly class PanelSettingsController
{
    public function __construct(
        private ServerOverviewReader $overviews,
        private SiteExtrasReader $extras,
        private Factory $views,
        private Translator $translator,
        private ?DnsService $dns = null,
        private ?Repository $config = null,
        private ?PanelMail $mail = null,
    ) {
    }

    public function index(Request $request): mixed
    {
        $actor = ResolvePanelActor::of($request);
        $read = $this->overviews->read($actor);
        $overview = $read->overview;
        $capabilities = $overview->capabilities ?? PanelCapabilities::none();
        $rows = [];

        // Who may open the panel, with the address it answers on as the hint.
        if ($capabilities->allows('panel:access') && $capabilities->allows('firewall:status')) {
            $access = $this->extras->firewall($actor)?->panelAccess;
            $address = $capabilities->allows('panel:address') ? $this->extras->panelAddress($actor) : null;
            $rows[] = $this->row('access', match (true) {
                $access === null => $this->text('panel_settings.unknown'),
                is_string($access) => $this->text('panel_settings.rows.access_any'),
                default => $this->text('panel_settings.rows.access_list', ['count' => (string) count($access)]),
            }, route('larena.env.admin.panel.access', [], false), $address?->url !== null ? $this->text('panel_settings.rows.access_hint', ['url' => $address->url]) : null);
        }
        if ($capabilities->allows('self:alerts-channel')) {
            $kind = $capabilities->allows('self:alerts') ? ($this->extras->alerts($actor)?->channel['kind'] ?? null) : null;
            $rows[] = $this->row('alerts', $kind === null ? $this->text('panel_settings.unknown')
                : ($kind === 'off' ? $this->text('panel_settings.rows.alerts_off') : $this->text('alerts.kinds.'.$kind)), route('larena.env.admin.alerts.channel', [], false));
        }
        // The panel's own mail (owner decision C335): whether it is on, and its page with the card and the test letter.
        if ($this->mail !== null) {
            $state = $this->mail->state();
            $rows[] = $this->row('mail', (string) $this->translator->get('larena-env::mail.states.'.$state), route('larena.env.admin.settings.mail', [], false), null, 'open',
                match ($state) {
                    PanelMail::ON => 'success',
                    PanelMail::PASSWORD_LOST => 'error',
                    default => 'warning',
                });
        }
        $rows[] = $this->row('sign_in', $this->text('panel_settings.rows.sign_in_value'), '/admin/settings/general');
        if ($this->dns !== null) {
            $connected = $this->dns->connection();
            $rows[] = $this->row('dns', $connected === null ? $this->text('panel_settings.rows.dns_none')
                : $this->text('panel_settings.rows.dns_on').' · '.ucfirst($connected->provider), route('larena.env.admin.dns.connection', [], false));
        }
        if ($capabilities->allows('backup:storage-list')) {
            $storages = $this->extras->backupStorages($actor);
            $rows[] = $this->row('storages', $storages === null ? $this->text('panel_settings.unknown')
                : ($storages === [] ? $this->text('panel_settings.rows.storages_none') : $this->text('panel_settings.rows.storages_count', ['count' => (string) count($storages)])),
                route('larena.env.admin.storages', [], false), null, 'look');
        }
        if ($capabilities->allows('security:status')) {
            $status = $this->extras->security($actor);
            $findings = $status === null ? null : SecurityFindings::of($status, $this->config === null ? [$request->getHost()] : ServerAdminController::panelHosts($request, $this->config));
            $counts = $findings?->counts();
            $rows[] = $this->row('security', $counts === null ? $this->text('panel_settings.unknown')
                : ($counts['now'] + $counts['todo'] === 0 ? $this->text('panel_settings.rows.security_fine')
                    : $this->text('panel_settings.rows.security_value', ['now' => (string) $counts['now'], 'todo' => (string) $counts['todo']])),
                route('larena.env.admin.security', [], false), $this->text('panel_settings.rows.security_hint'), 'look',
                $counts === null ? null : ($counts['now'] > 0 ? 'error' : ($counts['todo'] > 0 ? 'warning' : 'success')));
        }
        if ($capabilities->allows('self:update-plan')) {
            $versions = UpdateController::versions($overview);
            $rows[] = $this->row('updates', $this->text('panel_settings.rows.updates_value', ['env' => $versions['env']['installed'] ?? '—', 'panel' => $versions['panel']['installed'] ?? '—']),
                route('larena.env.admin.update', [], false), null, 'look');
        }

        return $this->views->make('larena-env::admin.settings', [
            'source' => $read->source,
            'rows' => $rows,
            'notice' => $request->session()->get('larena_env_notice'),
        ]);
    }

    /** @return array<string, mixed> one row of the map: the section, its value now, and the way to change it */
    private function row(string $id, string $value, string $href, ?string $hint = null, string $action = 'open', ?string $status = null): array
    {
        return array_filter([
            'id' => $id,
            'label' => $this->text('panel_settings.rows.'.$id),
            'value' => $value,
            'hint' => $hint,
            'status' => $status,
            'action' => ['label' => $this->text('panel_settings.'.$action), 'href' => $href],
        ], static fn (mixed $value): bool => $value !== null);
    }

    /** @param array<string, string> $replace */
    private function text(string $key, array $replace = []): string
    {
        return (string) $this->translator->get('larena-env::admin.'.$key, $replace);
    }
}
