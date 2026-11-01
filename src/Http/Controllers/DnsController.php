<?php

declare(strict_types=1);

namespace Larena\Env\Http\Controllers;

use Illuminate\Contracts\Translation\Translator;
use Illuminate\Contracts\View\Factory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Larena\Env\Dns\DnsChange;
use Larena\Env\Dns\DnsFailed;
use Larena\Env\Dns\DnsService;
use Larena\Env\Http\Middleware\ResolvePanelActor;
use Larena\Env\Http\Support\NoticeRedirect;
use Larena\Env\Server\SiteDetailsReader;

/**
 * The panel's DNS (Panel plan, stage 7). The system level connects a provider, reads its zones and changes records;
 * both levels point their own site's domain at this server from the site card. A change is planned, shown in words
 * with the record as it is and as it will be, kept in the person's session, and only its confirmation makes it.
 */
final readonly class DnsController
{
    private const string PLANS = 'larena_env_dns_plans';
    private const int KEEP_PLANS = 5;

    public function __construct(
        private DnsService $dns,
        private SiteDetailsReader $sites,
        private Factory $views,
        private Translator $translator,
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

        return $this->views->make('larena-env::admin.dns.connection', [
            'connected' => $connected,
            'zones' => $zones,
            'reasonText' => $reason,
            'notice' => $request->session()->get('larena_env_notice'),
        ]);
    }

    public function connect(Request $request): RedirectResponse
    {
        $token = (string) $request->input('token');
        try {
            $zones = $this->dns->connect($token, $this->person($request));
        } catch (DnsFailed $failed) {
            return $this->back($request, route('larena.env.admin.dns.connection', [], false), $failed->reason);
        }

        return NoticeRedirect::to($request, route('larena.env.admin.dns.connection', [], false),
            (string) $this->translator->get('larena-env::admin.dns.connected', ['count' => (string) count($zones)]));
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
        $this->dns->disconnect($this->person($request));

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
     * @param array{changes: list<DnsChange>, site: ?string, undo_of: ?string} $plan
     */
    private function applied(Request $request, array $plan, string $back): RedirectResponse
    {
        try {
            $this->dns->apply($plan['changes'], $this->person($request), $plan['undo_of']);
        } catch (DnsFailed $failed) {
            return $this->back($request, $back, $failed->reason);
        }

        return NoticeRedirect::to($request, $back, (string) $this->translator->get('larena-env::admin.dns.applied', ['count' => (string) count($plan['changes'])]));
    }

    /** @param list<DnsChange> $changes */
    private function showPlan(Request $request, array $changes, string $target, string $runUrl, string $cancelUrl, ?string $site, ?string $undoOf): mixed
    {
        $id = bin2hex(random_bytes(12));
        $plans = (array) $request->session()->get(self::PLANS, []);
        $plans[$id] = ['changes' => array_map(static fn (DnsChange $change): array => $change->toArray(), $changes), 'site' => $site, 'undo_of' => $undoOf];
        $request->session()->put(self::PLANS, array_slice($plans, -self::KEEP_PLANS, null, true));

        return $this->views->make('larena-env::admin.dns.plan', [
            'target' => $target,
            'changes' => $changes,
            'undo' => $undoOf !== null,
            'planId' => $id,
            'runUrl' => $runUrl,
            'cancelUrl' => $cancelUrl,
        ]);
    }

    /** @return array{changes: list<DnsChange>, site: ?string, undo_of: ?string}|null the plan confirmed, once */
    private function takePlan(Request $request): ?array
    {
        $id = (string) $request->input('plan');
        $plans = (array) $request->session()->get(self::PLANS, []);
        $plan = $plans[$id] ?? null;
        unset($plans[$id]);
        $request->session()->put(self::PLANS, $plans);
        if (!is_array($plan) || !is_array($plan['changes'] ?? null) || $plan['changes'] === []) {
            return null;
        }

        return [
            'changes' => array_map(static fn (array $change): DnsChange => DnsChange::fromArray($change), array_values($plan['changes'])),
            'site' => isset($plan['site']) ? (string) $plan['site'] : null,
            'undo_of' => isset($plan['undo_of']) ? (string) $plan['undo_of'] : null,
        ];
    }

    /** The person who did it, also when they look at the panel as someone else. */
    private function person(Request $request): string
    {
        $actor = ResolvePanelActor::of($request);

        return $actor->impersonatedBy ?? $actor->user;
    }

    private function back(Request $request, string $url, string $reason): RedirectResponse
    {
        return NoticeRedirect::to($request, $url, $this->reasonText($reason));
    }

    private function reasonText(string $reason): string
    {
        $key = 'larena-env::admin.dns.reasons.'.$reason;
        $line = (string) $this->translator->get($key);

        return $line !== $key ? $line : (string) $this->translator->get('larena-env::admin.dns.reasons.unknown', ['reason' => $reason]);
    }
}
