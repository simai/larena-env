<?php

declare(strict_types=1);

namespace Larena\Env\Http\Controllers;

use DateTimeImmutable;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Contracts\View\Factory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Larena\Access\Runtime\AccessOperationAuthorizer;
use Larena\Env\Firewall\AddressList;
use Larena\Env\Gateway\PanelActor;
use Larena\Env\Gateway\PanelCapabilities;
use Larena\Env\Http\Middleware\ResolvePanelActor;
use Larena\Env\Http\Support\NoticeRedirect;
use Larena\Env\Operations\PanelOperations;
use Larena\Env\Server\ServerOverviewReader;
use Larena\Env\Server\SiteExtrasReader;

/**
 * «Сервер › Файрвол» (Panel plan 5.2) and «Настройки панели › Доступ к панели» (5.3), the system level's. Every change
 * is planned and confirmed, and then the server applies it and puts the previous rules back by itself unless the
 * change is kept in time: the keeping comes from the panel over the new rules, so a panel that shut itself out never
 * keeps it and the change is undone. SSH and the panel's own port can never be closed.
 */
final readonly class FirewallController
{
    /** The changes and the gateway command of each. */
    public const array OPERATIONS = ['rule-add' => 'firewall:rule-add', 'rule-remove' => 'firewall:rule-remove', 'block' => 'firewall:block',
        'unblock' => 'firewall:unblock', 'access' => 'panel:access'];
    /** How long an address stays blocked: an hour, a day, a week, or until it is unblocked. */
    public const array BLOCK_TTLS = ['3600', '86400', '604800', ''];
    /** Which page each kind of timed change is kept from. */
    public const array CHANGE_PAGES = ['firewall' => 'larena.env.admin.firewall', 'panel_access' => 'larena.env.admin.panel.access'];

    public function __construct(
        private PanelOperations $operations,
        private ServerOverviewReader $overviews,
        private SiteExtrasReader $extras,
        private Factory $views,
        private Translator $translator,
        private ?AccessOperationAuthorizer $authorizer = null,
    ) {
    }

    public function index(Request $request): mixed
    {
        return $this->page($request, 'firewall');
    }

    public function access(Request $request): mixed
    {
        return $this->page($request, 'panel_access');
    }

    /** @param 'firewall'|'panel_access' $page */
    private function page(Request $request, string $page): mixed
    {
        $actor = ResolvePanelActor::of($request);
        $read = $this->overviews->read($actor);
        $capabilities = $read->overview->capabilities ?? PanelCapabilities::none();
        $supported = $capabilities->allows('firewall:status');
        $status = $supported ? $this->extras->firewall($actor) : null;
        $canOperate = $this->authorizer?->authorize($request, 'env.server.operate')->isAllowed() ?? false;
        $allows = [];
        foreach (self::OPERATIONS as $op => $command) {
            $allows[$op] = $canOperate && $capabilities->allows($command);
        }
        $allows['keep'] = $canOperate && $capabilities->allows('change:confirm');
        $address = (string) $request->ip();
        $panelAccess = $status?->panelAccess;

        return $this->views->make($page === 'firewall' ? 'larena-env::admin.firewall' : 'larena-env::admin.panel-access', [
            'source' => $read->source,
            'supported' => $page === 'firewall' ? $supported : $supported && $capabilities->allows('panel:access'),
            'status' => $status,
            'allows' => $allows,
            'pending' => $status?->pendingOf($page),
            'now' => new DateTimeImmutable(),
            'ttls' => self::BLOCK_TTLS,
            // Where this person reaches the panel from: a list without it would shut them out, and the server would undo it.
            'address' => $address,
            'addressListed' => $panelAccess === 'any' || $panelAccess === null || AddressList::contains($panelAccess, $address),
            'notice' => $request->session()->get('larena_env_notice'),
        ]);
    }

    /** What the change would do, then the plan's confirmation; the screen after it says how long it waits to be kept. */
    public function plan(Request $request): mixed
    {
        $actor = ResolvePanelActor::of($request);
        $op = (string) $request->input('op');
        $back = $op === 'access' ? 'panel_access' : 'firewall';
        $args = $this->arguments($request, $actor, $op);
        if (is_string($args)) {
            return $this->back($request, $back, $args);
        }
        $result = $this->operations->planCommand($actor, self::OPERATIONS[$op], $args);
        if ($result->plan === null) {
            return $this->back($request, $back, $result->reason ?? 'answer_shape_invalid');
        }
        $hidden = ['op' => $op];
        foreach ($args as $name => $value) {
            foreach (is_array($value) ? $value : [null => $value] as $index => $item) {
                $hidden['args['.$name.']'.(is_int($index) ? '['.$index.']' : '')] = $item;
            }
        }

        return $this->views->make('larena-env::admin.plan', [
            'heading' => (string) $this->translator->get('larena-env::admin.firewall.operations.'.$op),
            'eyebrow' => (string) $this->translator->get('larena-env::admin.'.($back === 'firewall' ? 'firewall' : 'panel_access').'.title'),
            'target' => (string) $this->translator->get('larena-env::admin.firewall.target'),
            'source' => $result->source,
            'plan' => $result->plan,
            'runUrl' => route('larena.env.admin.firewall.run', [], false),
            'cancelUrl' => route(self::CHANGE_PAGES[$back], [], false),
            'hidden' => $hidden,
            'then' => [(string) $this->translator->get('larena-env::admin.timed.after_plan')],
        ]);
    }

    /** The confirmation: the server applies the change and the job's screen offers to keep it in time. */
    public function run(Request $request): RedirectResponse
    {
        $actor = ResolvePanelActor::of($request);
        $op = (string) $request->input('op');
        $back = $op === 'access' ? 'panel_access' : 'firewall';
        $typed = (array) $request->input('args', []);
        $args = $this->arguments($request, $actor, $op, $typed);
        if (is_string($args)) {
            return $this->back($request, $back, $args);
        }
        $started = $this->operations->startCommand($actor, self::OPERATIONS[$op], $args);
        if ($started->jobId === null) {
            return $this->back($request, $back, $started->reason ?? 'answer_shape_invalid');
        }

        return new RedirectResponse(route('larena.env.admin.job', ['job' => $started->jobId], false));
    }

    /**
     * Keeps a timed change (change:confirm) at once: it was planned and confirmed already, and the time to keep it is
     * short. Back to the page it was made from, with what happened in words.
     */
    public function keep(Request $request): RedirectResponse
    {
        $actor = ResolvePanelActor::of($request);
        $change = $request->input('change');
        $kind = $request->input('kind');
        $page = is_string($kind) && isset(self::CHANGE_PAGES[$kind]) ? route(self::CHANGE_PAGES[$kind], [], false) : route('larena.env.admin.index', [], false);
        $capabilities = $this->overviews->read($actor)->overview->capabilities ?? PanelCapabilities::none();
        if (!is_string($change) || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/D', $change) !== 1 || !$capabilities->allows('change:confirm')) {
            return NoticeRedirect::to($request, $page, $this->reasonText('not_allowed'));
        }
        $kept = $this->operations->startCommand($actor, 'change:confirm', ['change_id' => $change]);

        return NoticeRedirect::to($request, $page, $kept->jobId !== null
            ? (string) $this->translator->get('larena-env::admin.timed.kept')
            : $this->reasonText($kept->reason ?? 'answer_shape_invalid'));
    }

    /**
     * The gateway arguments of the change from the form, or the reason it is refused before anything is asked: an
     * operation the server does not list, an address that is none, or a protected rule (SSH, the panel's port).
     *
     * @param array<array-key, mixed>|null $planned the arguments a plan's confirmation sends back, as the plan named them
     *
     * @return array<string, string|list<string>>|string
     */
    private function arguments(Request $request, PanelActor $actor, string $op, ?array $planned = null): array|string
    {
        $command = self::OPERATIONS[$op] ?? null;
        $capabilities = $this->overviews->read($actor)->overview->capabilities ?? PanelCapabilities::none();
        if ($command === null || !$capabilities->allows($command)) {
            return 'not_allowed';
        }
        $field = static function (string $name) use ($request, $planned): string {
            $value = $planned !== null ? ($planned[$name] ?? null) : $request->input($name);

            return is_string($value) ? trim($value) : '';
        };
        switch ($op) {
            case 'rule-add':
                $args = ['port' => $field('port'), 'action' => $field('action') === 'deny' ? 'deny' : 'allow'];
                $proto = $field('proto');
                if (in_array($proto, ['tcp', 'udp'], true)) {
                    $args['proto'] = $proto;
                }
                $from = $field('from');
                if ($from !== '' && $from !== 'any') {
                    if (!AddressList::valid($from)) {
                        return 'address_invalid';
                    }
                    $args['from'] = $from;
                }

                return $args;
            case 'rule-remove':
                $id = $field('id');
                $rule = null;
                foreach ($this->extras->firewall($actor)->rules ?? [] as $candidate) {
                    if ($candidate->id === $id) {
                        $rule = $candidate;
                    }
                }
                if ($rule === null) {
                    return 'not_found';
                }

                return $rule->protected ? 'firewall_protected' : ['id' => $id];
            case 'block':
                $address = $field('address');
                if (!AddressList::valid($address)) {
                    return 'address_invalid';
                }
                $ttl = $field('ttl');

                return in_array($ttl, self::BLOCK_TTLS, true) && $ttl !== '' ? ['address' => $address, 'ttl' => $ttl] : ['address' => $address];
            case 'unblock':
                $address = $field('address');

                return AddressList::valid($address) ? ['address' => $address] : 'address_invalid';
            case 'access':
                if ($planned !== null) {
                    $allow = $planned['allow'] ?? null;
                    $list = $allow === 'any' ? 'any' : (is_array($allow) && array_is_list($allow) && array_filter($allow, 'is_string') === $allow ? $allow : null);
                    if ($list === null || (is_array($list) && ($list === [] || array_filter($list, AddressList::valid(...)) !== $list))) {
                        return 'address_invalid';
                    }

                    return ['allow' => $list];
                }
                if ($request->input('everyone') === 'yes') {
                    return ['allow' => 'any'];
                }
                $list = AddressList::parse((string) $request->input('allow'));

                return $list === null || $list === [] ? 'address_invalid' : ['allow' => $list];
        }

        return 'not_allowed';
    }

    /** @param 'firewall'|'panel_access' $page */
    private function back(Request $request, string $page, string $reason): RedirectResponse
    {
        return NoticeRedirect::to($request, route(self::CHANGE_PAGES[$page], [], false), $this->reasonText($reason));
    }

    private function reasonText(string $reason): string
    {
        $key = 'larena-env::admin.reasons.'.$reason;
        $line = (string) $this->translator->get($key);

        return $line !== $key ? $line : (string) $this->translator->get('larena-env::admin.reasons.unknown', ['reason' => $reason]);
    }
}
