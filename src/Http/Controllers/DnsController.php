<?php

declare(strict_types=1);

namespace Larena\Env\Http\Controllers;

use Illuminate\Contracts\Translation\Translator;
use Illuminate\Contracts\View\Factory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Larena\Access\Runtime\AccessOperationAuthorizer;
use Larena\Env\Dns\DnsChange;
use Larena\Env\Dns\DnsFailed;
use Larena\Env\Dns\DnsService;
use Larena\Env\Operations\EnvOperations;
use Larena\Env\Http\Middleware\ResolvePanelActor;
use Larena\Env\Http\Support\NoticeRedirect;
use Larena\Env\Http\Support\PlanReceipts;
use Larena\Env\Gateway\PanelCapabilities;
use Larena\Env\Server\ServerOverviewReader;
use Larena\Env\Server\SiteDetailsReader;
use Larena\Env\Server\SiteExtrasReader;

/**
 * The panel's DNS (Panel plan, stage 7). The system level connects a provider, reads its zones and changes records;
 * both levels point their own site's domain at this server from the site card. A change is planned, shown in words
 * with the record as it is and as it will be, kept in the person's session, and only its confirmation makes it.
 */
final readonly class DnsController
{
    public function __construct(
        private DnsService $dns,
        private SiteDetailsReader $sites,
        private Factory $views,
        private Translator $translator,
        private ?ServerOverviewReader $overviews = null,
        private ?SiteExtrasReader $extras = null,
        private ?AccessOperationAuthorizer $authorizer = null,
        private PlanReceipts $receipts = new PlanReceipts(),
        // Every change of DNS is a Core operation (env.dns.connect, env.dns.disconnect, env.dns.records_apply,
        // env.site.dns_point): its right checked and its decision audited before DnsService touches anything.
        private ?EnvOperations $operations = null,
    ) {
    }

    public function connection(Request $request): mixed
    {
        $zones = null;
        $reason = null;
        $connected = $this->dns->connection();
        if ($connected !== null) {
            try {
                $zones = $this->dns->zones();
            } catch (DnsFailed $failed) {
                $reason = $this->reasonText($failed->reason);
            }
        }

        // Certificates through DNS (simai-env 1.36.0): whether the server keeps a token, and who may give it the panel's.
        $actor = ResolvePanelActor::of($request);
        $capabilities = $this->overviews?->read($actor)->overview->capabilities ?? PanelCapabilities::none();
        $certificates = $capabilities->allows('ssl:dns-status') ? $this->extras?->dnsCertificates($actor) : null;

        return $this->views->make('larena-env::admin.dns.connection', [
            'connected' => $connected,
            'zones' => $zones,
            'reasonText' => $reason,
            'certificatesSupported' => $capabilities->allows('ssl:dns-status'),
            'certificates' => $certificates,
            'certificatesAllowed' => $capabilities->allows('ssl:dns-credentials')
                && ($this->authorizer?->authorize($request, 'env.server.operate')->isAllowed() ?? false)
                && $this->authorizer->authorize($request, 'env.dns.operate')->isAllowed(),
            'notice' => $request->session()->get('larena_env_notice'),
        ]);
    }

    public function connect(Request $request): RedirectResponse
    {
        $token = (string) $request->input('token');
        try {
            $zones = $this->change($request, 'env.dns.connect', ['token' => $token])['zones'] ?? 0;
        } catch (DnsFailed $failed) {
            return $this->back($request, route('larena.env.admin.dns.connection', [], false), $failed->reason);
        }

        return NoticeRedirect::to($request, route('larena.env.admin.dns.connection', [], false),
            (string) $this->translator->get('larena-env::admin.dns.connected', ['count' => (string) (is_int($zones) ? $zones : 0)]));
    }

    public function addresses(Request $request): RedirectResponse
    {
        $value = static fn (string $field): ?string => trim((string) $request->input($field)) === '' ? null : trim((string) $request->input($field));
        try {
            $this->dns->setServerAddresses($value('ipv4'), $value('ipv6'));
        } catch (DnsFailed $failed) {
            return $this->back($request, route('larena.env.admin.dns.connection', [], false), $failed->reason);
        }

        return NoticeRedirect::to($request, route('larena.env.admin.dns.connection', [], false), (string) $this->translator->get('larena-env::admin.dns.addresses_saved'));
    }

    public function disconnect(Request $request): RedirectResponse
    {
        try {
            $this->change($request, 'env.dns.disconnect', []);
        } catch (DnsFailed $failed) {
            return $this->back($request, route('larena.env.admin.dns.connection', [], false), $failed->reason);
        }

        return NoticeRedirect::to($request, route('larena.env.admin.dns.connection', [], false), (string) $this->translator->get('larena-env::admin.dns.disconnected'));
    }

    public function index(Request $request): mixed
    {
        $zones = null;
        $reason = null;
        if ($this->dns->connection() !== null) {
            try {
                $zones = $this->dns->zones();
            } catch (DnsFailed $failed) {
                $reason = $this->reasonText($failed->reason);
            }
        }

        return $this->views->make('larena-env::admin.dns.index', [
            'connected' => $this->dns->connection(),
            'zones' => $zones,
            'reasonText' => $reason,
            'notice' => $request->session()->get('larena_env_notice'),
        ]);
    }

    public function zone(Request $request, string $zone): mixed
    {
        try {
            $found = $this->dns->zone($zone);
            $records = $this->dns->records($found);
        } catch (DnsFailed $failed) {
            return $this->back($request, route('larena.env.admin.dns', [], false), $failed->reason);
        }

        return $this->views->make('larena-env::admin.dns.zone', [
            'zone' => $found,
            'records' => $records,
            'last' => $this->dns->lastBatch($found),
            'types' => \Larena\Env\Dns\DnsRecordInput::TYPES,
            'edit' => (string) $request->query('edit'),
            'notice' => $request->session()->get('larena_env_notice'),
        ]);
    }

    public function plan(Request $request, string $zone): mixed
    {
        $back = route('larena.env.admin.dns.zone', ['zone' => $zone], false);
        try {
            $found = $this->dns->zone($zone);
            $changes = $this->dns->planRecord($found, (string) $request->input('action'), $request->input('record') === null ? null : (string) $request->input('record'),
                (array) $request->only(['type', 'name', 'content', 'ttl', 'proxied', 'priority']));
        } catch (DnsFailed $failed) {
            return $this->back($request, $back, $failed->reason);
        }

        return $this->showPlan($request, $changes, $found->name, route('larena.env.admin.dns.run', [], false), $back, null, null);
    }

    public function undo(Request $request, string $zone): mixed
    {
        $back = route('larena.env.admin.dns.zone', ['zone' => $zone], false);
        try {
            $found = $this->dns->zone($zone);
            $last = $this->dns->lastBatch($found);
            $changes = $this->dns->planUndo($found);
        } catch (DnsFailed $failed) {
            return $this->back($request, $back, $failed->reason);
        }

        return $this->showPlan($request, $changes, $found->name, route('larena.env.admin.dns.run', [], false), $back, null, $last['batch'] ?? null);
    }

    public function run(Request $request): RedirectResponse
    {
        $plan = $this->takePlan($request);
        if ($plan === null || $plan['site'] !== null) {
            return $this->back($request, route('larena.env.admin.dns', [], false), 'plan_expired');
        }
        $back = route('larena.env.admin.dns.zone', ['zone' => $plan['changes'][0]->zoneId], false);

        return $this->applied($request, $plan, $back);
    }

    /** The site card's «point at this server», at either level, for a site the person reaches. */
    public function sitePlan(Request $request, string $domain): mixed
    {
        $back = route('larena.env.admin.site', ['domain' => $domain], false).'?tab=dns';
        if ($this->sites->read(ResolvePanelActor::of($request), $domain)->site === null) {
            return $this->back($request, $back, 'not_allowed');
        }
        try {
            $changes = $this->dns->planPointHere($domain);
        } catch (DnsFailed $failed) {
            return $this->back($request, $back, $failed->reason);
        }

        return $this->showPlan($request, $changes, $domain, route('larena.env.admin.site.dns.run', ['domain' => $domain], false), $back, strtolower($domain), null);
    }

    public function siteRun(Request $request, string $domain): RedirectResponse
    {
        $back = route('larena.env.admin.site', ['domain' => $domain], false).'?tab=dns';
        $plan = $this->takePlan($request);
        if ($plan === null || $plan['site'] !== strtolower($domain)) {
            return $this->back($request, $back, 'plan_expired');
        }
        if ($this->sites->read(ResolvePanelActor::of($request), $domain)->site === null) {
            return $this->back($request, $back, 'not_allowed');
        }
        // A plan of a site touches only the site's domain and its www.
        foreach ($plan['changes'] as $change) {
            $name = $change->after->name ?? $change->before->name ?? '';
            if (!in_array($name, [strtolower($domain), 'www.'.strtolower($domain)], true)) {
                return $this->back($request, $back, 'not_allowed');
            }
        }

        return $this->applied($request, $plan, $back);
    }

    /**
     * @param array{changes: list<DnsChange>, site: ?string, undo_of: ?string, plan_id: ?string} $plan
     */
    private function applied(Request $request, array $plan, string $back): RedirectResponse
    {
        try {
            // The chain of the change (wave A's addition c): who, for whom, and the plan it was confirmed with; a site's own
            // domain is its own operation, at either level.
            $this->change($request, $plan['site'] !== null ? 'env.site.dns_point' : 'env.dns.records_apply', array_filter([
                'domain' => $plan['site'],
                'changes' => array_map(static fn (DnsChange $change): array => $change->toArray(), $plan['changes']),
                'undo_of' => $plan['site'] === null ? $plan['undo_of'] : null,
                'plan_id' => $plan['plan_id'] ?? null,
            ], static fn (mixed $value): bool => $value !== null));
        } catch (DnsFailed $failed) {
            return $this->back($request, $back, $failed->reason);
        }

        return NoticeRedirect::to($request, $back, (string) $this->translator->get('larena-env::admin.dns.applied', ['count' => (string) count($plan['changes'])]));
    }

    /** @param list<DnsChange> $changes */
    private function showPlan(Request $request, array $changes, string $target, string $runUrl, string $cancelUrl, ?string $site, ?string $undoOf): mixed
    {
        // The change waits in the plan's receipt (PlanReceipts): the confirmation sends back only its id.
        $id = $this->receipts->issue($request, 'dns:change', [],
            ['changes' => array_map(static fn (DnsChange $change): array => $change->toArray(), $changes), 'site' => $site, 'undo_of' => $undoOf]);

        return $this->views->make('larena-env::admin.dns.plan', [
            'target' => $target,
            'changes' => $changes,
            'undo' => $undoOf !== null,
            'planId' => $id,
            'runUrl' => $runUrl,
            'cancelUrl' => $cancelUrl,
        ]);
    }

    /** @return array{changes: list<DnsChange>, site: ?string, undo_of: ?string, plan_id: ?string}|null the plan confirmed, once */
    private function takePlan(Request $request): ?array
    {
        $plan = $this->receipts->take($request, 'dns:change', 'plan');
        if (!is_array($plan) || !is_array($plan['changes'] ?? null) || $plan['changes'] === []) {
            return null;
        }

        return [
            'changes' => array_map(static fn (array $change): DnsChange => DnsChange::fromArray($change), array_values($plan['changes'])),
            'site' => isset($plan['site']) ? (string) $plan['site'] : null,
            'undo_of' => isset($plan['undo_of']) ? (string) $plan['undo_of'] : null,
            'plan_id' => is_string($request->input('plan')) && $request->input('plan') !== '' ? (string) $request->input('plan') : null,
        ];
    }

    /**
     * A change of DNS as the panel's Core operation; refused `audit_unavailable` where the operations are not composed.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     *
     * @throws DnsFailed
     */
    private function change(Request $request, string $operation, array $input): array
    {
        if ($this->operations === null) {
            throw new DnsFailed('audit_unavailable');
        }

        return $this->operations->dns(ResolvePanelActor::of($request), $operation, $input);
    }

    private function back(Request $request, string $url, string $reason): RedirectResponse
    {
        return NoticeRedirect::to($request, $url, $this->reasonText($reason));
    }

    private function reasonText(string $reason): string
    {
        $key = 'larena-env::admin.dns.reasons.'.$reason;
        $line = (string) $this->translator->get($key);

        if ($line !== $key) {
            return $line;
        }
        // A reason the whole panel names (audit_unavailable) in the panel's words.
        $panel = 'larena-env::admin.reasons.'.$reason;
        $line = (string) $this->translator->get($panel);

        return $line !== $panel ? $line : (string) $this->translator->get('larena-env::admin.dns.reasons.unknown', ['reason' => $reason]);
    }
}
