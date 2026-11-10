<?php

declare(strict_types=1);

namespace Larena\Env\Http\Controllers;

use Larena\Env\Gateway\PanelCommands;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Contracts\View\Factory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Larena\Env\Gateway\PanelActor;
use Larena\Env\Http\Middleware\ResolvePanelActor;
use Larena\Env\Http\Support\NoticeRedirect;
use Larena\Env\Operations\PanelOperations;
use Larena\Env\Server\ServerOverviewReader;

/**
 * A new site (Panel plan 2.2): the person names the domain and what it runs; its account, nginx, PHP and, if asked, its
 * database come with it. Like any action it is planned, shown in words, and created only when that plan is confirmed.
 * A person at the user level always creates it in their own space: the gateway sets the owner to theirs.
 */
final readonly class NewSiteController
{
    /**
     * The kinds every simai-env since 1.22.0 creates (site:add profiles). A server that lists its own (self:profiles,
     * Panel plan 5.5) is asked instead: then bitrix and a reverse proxy to a port of this server come with it.
     */
    public const array PROFILES = ['static', 'generic', 'laravel', 'wordpress', 'node', 'redirect'];
    /** The kinds the panel has words for; any other the server lists is shown by its name and the server's line. */
    public const array KNOWN_PROFILES = [...self::PROFILES, 'bitrix', 'proxy'];
    private const array PHP_PROFILES = ['generic', 'laravel', 'wordpress'];
    private const array FIELDS = ['domain', 'profile', 'php', 'owner', 'create-db', 'db-engine', 'redirect-to', 'upstream-port', 'upstream-path', 'websockets',
        'bitrix-files'];
    /** The form's groups of fields and the site:add arguments each sends, asked only of a kind that takes them. */
    public const array FIELD_GROUPS = ['php' => ['php'], 'database' => ['create-db', 'db-engine'], 'redirect' => ['redirect-to'],
        'proxy' => ['upstream-port', 'upstream-path', 'websockets'], 'bitrix' => ['bitrix-files']];
    /** The installer a 1C-Bitrix site may get in its root (simai-env 1.33.0): none yet, a new install, or a restore. */
    public const array BITRIX_FILES = ['none', 'setup', 'restore'];
    /** The session key that holds, by id, the values a property view was filled for until its plan is shown. */
    public const string DRAFTS = 'larena_env.new_site_drafts';
    /** The session key that holds, by job, the new site whose daily backup is turned on once the site is made. */
    public const string AFTER_JOB = 'larena_env.after_job';
    /**
     * The session key that holds, by domain, what the person gave for the later steps of the site's creation route (a
     * WordPress title and administrator, a repository): the site's card offers them again at that step. Never a password.
     */
    public const string ROUTE_VALUES = 'larena_env.route_values';

    public function __construct(
        private ServerOverviewReader $overviews,
        private PanelOperations $operations,
        private Factory $views,
        private Translator $translator,
        private ?\Larena\Env\Server\SiteExtrasReader $extras = null,
    ) {
    }

    public function create(Request $request): mixed
    {
        $actor = ResolvePanelActor::of($request);
        $overview = $this->overviews->read($actor)->overview;
        $server = $this->serverProfiles($actor, $overview);
        $profiles = $server === null ? self::PROFILES : array_keys($server);
        $kind = in_array($request->query('profile'), $profiles, true) ? (string) $request->query('profile') : ($profiles[0] ?? 'static');
        // The ways each kind is made (simai-env 1.56.0), where the server lists them: a kind none of whose ways the panel
        // can lead now is shown with the reason, never as a link.
        $allows = static fn (string $command): bool => $overview?->capabilities?->allows($command) === true;
        $routed = $server !== null && array_filter($server, static fn (\Larena\Env\Server\SiteProfile $profile): bool => $profile->routes !== []) !== [];
        $routes = null;
        $route = null;
        $unavailable = [];
        if ($routed) {
            foreach ($server as $name => $profile) {
                if (array_filter($profile->routes, static fn (\Larena\Env\Server\SiteRoute $one): bool => $one->blockedBy($allows) === null) === []) {
                    $unavailable[$name] = $this->text('routes.unavailable.profile');
                }
            }
            if (isset($unavailable[$kind])) {
                $kind = array_values(array_diff($profiles, array_keys($unavailable)))[0] ?? $kind;
            }
            $routes = $this->routeChoices($kind, $server[$kind] ?? null, $allows);
            $asked = $request->query('route');
            $open = array_values(array_filter($routes, static fn (array $one): bool => $one['available']));
            foreach ($open as $one) {
                if ($one['id'] === $asked) {
                    $route = $one['id'];
                }
            }
            $route ??= $open[0]['id'] ?? null;
        }

        return $this->views->make('larena-env::admin.new-site', [
            // The ways the chosen kind is made, the one chosen and the fields it asks (null from a server without routes).
            'routes' => $routes,
            'route' => $route,
            'routeFields' => $route === null ? [] : $this->routeFields($server[$kind]->route($route), $server[$kind], $overview),
            'routeFiles' => $route === null ? [] : $this->routeFiles($server[$kind]->route($route)),
            'unavailable' => $unavailable,
            'profiles' => $profiles,
            'phpProfiles' => self::PHP_PROFILES,
            // The server's own kinds (Panel plan 5.5): what each is called and which fields it asks.
            'serverProfiles' => $server === null ? null : $this->described($server),
            'phpVersions' => $overview->phpVersions ?? [],
            // Only the whole server chooses whose the site is; a person's own site is theirs.
            'owners' => $actor->owner === null ? ($overview->owners ?? []) : null,
            'old' => self::input($request->all(), $actor, $server),
            // A route's fields as the person left them (a refusal brings them back in the address).
            'routeOld' => self::strings($request->query()),
            // What it runs is chosen first; the form then asks only what that kind takes.
            'kind' => $kind,
            // A new site gets its daily backup by default (Panel plan 4.1), where the server's gateway can set one.
            'dailyBackup' => $this->schedules($overview) ? self::dailyBackup(self::strings($request->all())) : null,
            'notice' => $request->session()->get('larena_env_notice'),
            // The chosen kind's fields in the Framework property view, over its port (NewSitePropertyViewPort); the
            // plain form below stays for a page without it.
            'propertyView' => ['port' => route('larena.env.admin.sites.port', [], false), 'scope' => \Larena\Env\Admin\NewSitePropertyViewPort::SCOPE,
                'collection' => \Larena\Env\Admin\NewSitePropertyViewPort::COLLECTION.$kind.($route !== null ? '.'.$route : '')],
        ]);
    }

    public function plan(Request $request): mixed
    {
        return $this->showPlan($request, self::strings($request->all()));
    }

    /**
     * The plan of a site the property view was filled for (NewSitePropertyViewPort): the same plan the form makes, from
     * what the port kept in this person's session under the draft's id.
     */
    public function planDraft(Request $request, string $draft): mixed
    {
        $raw = $request->session()->get(self::DRAFTS.'.'.$draft);
        if (!is_array($raw)) {
            return NoticeRedirect::to($request, route('larena.env.admin.sites.new', [], false), $this->reasonText('new_site_draft_gone'));
        }

        return $this->showPlan($request, self::strings($raw));
    }

    /**
     * The property view's port of a new site (simai.property-view-port): its fields for the chosen kind, and
     * record.create, which plans site:add as the form does and leads to that plan.
     */
    public function port(Request $request, \Larena\Env\Admin\NewSitePropertyViewPort $port): \Illuminate\Http\JsonResponse
    {
        $intent = $request->input('intent');
        $payload = $request->input('payload');
        if (!$request->isJson() || strlen($request->getContent()) > 65_536
            || !is_string($intent) || !in_array($intent, \Larena\Env\Admin\NewSitePropertyViewPort::INTENTS, true)
            || !is_array($payload) || ($payload !== [] && array_is_list($payload))) {
            return new \Illuminate\Http\JsonResponse(['answer' => 'refused', 'reason' => 'port_request_invalid'], 422);
        }
        $access = $request->attributes->get('larena_access_actor');
        try {
            $answer = $port->for(ResolvePanelActor::of($request), $request->session())
                ->raise(is_string($access) && $access !== '' ? $access : 'panel', \Larena\Env\Admin\NewSitePropertyViewPort::SCOPE, $intent, $payload);
        } catch (\Throwable) {
            $answer = ['answer' => 'unavailable', 'reason' => 'port_failed'];
        }
        $status = match ($answer['answer'] ?? null) {'applied' => 200, 'conflict' => 409, 'refused' => 422, default => 503};

        return new \Illuminate\Http\JsonResponse($answer, $status, ['Cache-Control' => 'private, no-store']);
    }

    /**
     * What a new site's form offers this person: the kinds (the server's own where it lists them), the server's
     * overview and whether a daily backup can be set.
     *
     * @return array{profiles: list<string>, server: array<string, \Larena\Env\Server\SiteProfile>|null, overview: ?\Larena\Env\Server\ServerOverview, schedules: bool}
     */
    public function offer(PanelActor $actor): array
    {
        $overview = $this->overviews->read($actor)->overview;
        $server = $this->serverProfiles($actor, $overview);

        return ['profiles' => $server === null ? self::PROFILES : array_keys($server), 'server' => $server, 'overview' => $overview,
            'schedules' => $this->schedules($overview)];
    }

    /**
     * The plan of site:add for a form's values, as the plan screen asks it: the arguments the kind takes, with the
     * daily backup where the server makes it with the site.
     *
     * @param array<string, string> $raw
     * @return array{0: \Larena\Env\Operations\PlanResult, 1: array<string, string>}
     */
    public function planFor(PanelActor $actor, array $raw): array
    {
        $offer = $this->offer($actor);
        $input = self::withBackup(self::input($raw, $actor, $offer['server']), $raw, $offer['overview'], $offer['server']);

        return [$this->operations->planCommand($actor, 'site:add', $input), $input];
    }

    /** @param array<string, string> $raw */
    private function showPlan(Request $request, array $raw): mixed
    {
        $actor = ResolvePanelActor::of($request);
        $overview = $this->overviews->read($actor)->overview;
        $server = $this->serverProfiles($actor, $overview);
        $input = self::withBackup(self::input($raw, $actor, $server), $raw, $overview, $server);
        $route = self::routeOf($raw, $server);
        $later = $route === null ? [] : self::laterValues($raw, $route);
        $wrong = $route === null ? null : self::wrongLater($raw, $route);
        if ($wrong !== null) {
            return $this->backWith($request, $this->text('routes.invalid', ['field' => $this->inputLabel($wrong, null)]), $input + ['route' => $route->id]);
        }
        $result = $this->operations->planCommand($actor, 'site:add', $input);
        if ($result->plan === null) {
            return $this->back($request, $result->reason ?? 'answer_shape_invalid', $input + ($route === null ? [] : ['route' => $route->id]));
        }
        $then = [];
        foreach ($route->steps ?? [] as $step) {
            if ($step['command'] !== 'site:add') {
                $then[] = $this->stepLabel($step);
            }
        }

        return $this->views->make('larena-env::admin.plan', [
            'heading' => (string) $this->translator->get('larena-env::admin.new_site.heading'),
            'eyebrow' => $input['domain'] ?? '',
            'target' => $input['domain'] ?? '',
            'source' => $result->source,
            'plan' => $result->plan,
            'runUrl' => route('larena.env.admin.sites.run', [], false),
            'cancelUrl' => route('larena.env.admin.sites.new', [], false).(isset($input['profile']) ? '?profile='.$input['profile'] : '').($route !== null ? '&route='.$route->id : ''),
            'hidden' => $input + ['daily-backup' => self::dailyBackup($raw) ? 'yes' : 'no'] + ($route === null ? [] : ['route' => $route->id]) + $later,
            'then' => array_merge($then === [] ? [] : [$this->text('routes.then', ['steps' => implode(' → ', $then)])],
                $this->followsWithSchedule($raw, $overview, $input) ? [(string) $this->translator->get('larena-env::admin.new_site.daily_backup_after')] : []),
            // What the site asks of the server: its memory and, for a copy, the disk (simai-env 1.56.0).
            'resources' => $result->plan->resources,
        ]);
    }

    public function run(Request $request): RedirectResponse
    {
        $actor = ResolvePanelActor::of($request);
        $overview = $this->overviews->read($actor)->overview;
        $server = $this->serverProfiles($actor, $overview);
        $raw = self::strings($request->all());
        $input = self::withBackup(self::input($raw, $actor, $server), $raw, $overview, $server);
        $route = self::routeOf($raw, $server);
        if ($route !== null && self::wrongLater($raw, $route) !== null) {
            return $this->back($request, 'invalid_argument', $input + ['route' => $route->id]);
        }
        $started = $this->operations->startCommand($actor, 'site:add', $input);
        if ($started->jobId === null) {
            return $this->back($request, $started->reason ?? 'answer_shape_invalid', $input + ($route === null ? [] : ['route' => $route->id]));
        }
        // What the later steps of the route take, offered again on the site's card at that step.
        if ($route !== null && isset($input['domain'])) {
            $request->session()->put(self::ROUTE_VALUES.'.'.strtolower($input['domain']), ['route' => $route->id] + self::laterValues($raw, $route));
        }

        // The site's daily backup is turned on when its job has made it (ServerJobController), as the plan said.
        if ($this->followsWithSchedule($raw, $overview, $input, $server) && isset($input['domain'])) {
            $request->session()->put(self::AFTER_JOB.'.'.$started->jobId, $input['domain']);
        }

        return new RedirectResponse(route('larena.env.admin.job', ['job' => $started->jobId], false));
    }

    /** On unless the form turned it off: the form sends `no` before the box, which sends `yes` when it is ticked. */
    /** @param array<string, string> $raw */
    private static function dailyBackup(array $raw): bool
    {
        return ($raw['daily-backup'] ?? null) !== 'no';
    }

    /**
     * The text values of a request or a kept draft; anything else is not a form value.
     *
     * @param array<array-key, mixed> $values
     * @return array<string, string>
     */
    private static function strings(array $values): array
    {
        return array_filter($values, static fn (mixed $value, int|string $key): bool => is_string($key) && is_string($value), ARRAY_FILTER_USE_BOTH);
    }

    private function schedules(?\Larena\Env\Server\ServerOverview $overview): bool
    {
        return $overview?->capabilities?->allows('backup:data-schedule') === true
            || $overview?->capabilities?->takes('site:add', 'backup') === true;
    }

    /**
     * A server whose site:add takes `backup` (simai-env 1.30.0) makes the daily backup with the site, every kind but a
     * redirect; an older one gets backup:data-schedule once the site is made.
     *
     * @param array<string, string> $input
     * @param array<string, string> $raw
     *
     * @return array<string, string>
     */
    private static function withBackup(array $input, array $raw, ?\Larena\Env\Server\ServerOverview $overview, ?array $server = null): array
    {
        if ($overview?->capabilities?->takes('site:add', 'backup') === true && self::backsUp($input['profile'] ?? 'static', $server)) {
            $input['backup'] = self::dailyBackup($raw) ? 'yes' : 'no';
        }

        return $input;
    }

    /**
     * @param array<string, string> $raw
     * @param array<string, string> $input
     */
    private function followsWithSchedule(array $raw, ?\Larena\Env\Server\ServerOverview $overview, array $input, ?array $server = null): bool
    {
        return self::dailyBackup($raw) && !isset($input['backup']) && self::backsUp($input['profile'] ?? 'static', $server)
            && $overview?->capabilities?->allows('backup:data-schedule') === true;
    }

    /**
     * The gateway's arguments from the form, as typed; the command table refuses anything the gateway would. Each kind
     * sends only the fields it takes (PHP and a database for a PHP profile, the target for a redirect, the local port
     * for a proxy), the database engine only when one is asked for, and the owner only from the whole server.
     *
     * @param array<string, string> $raw the form's values
     * @param array<string, \Larena\Env\Server\SiteProfile>|null $server the server's own kinds, when it lists them
     *
     * @return array<string, string>
     */
    private static function input(array $raw, PanelActor $actor, ?array $server = null): array
    {
        $route = self::routeOf($raw, $server);
        if ($route !== null) {
            return self::routeInput($raw, $actor, $route);
        }
        $input = [];
        foreach (self::FIELDS as $field) {
            $value = $raw[$field] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $input[$field] = trim($value);
            }
        }
        $profile = $input['profile'] ?? null;
        $groups = self::groupsOf(is_string($profile) ? $profile : '', $server);
        foreach (self::FIELD_GROUPS as $group => $fields) {
            if (!in_array($group, $groups, true)) {
                foreach ($fields as $field) {
                    unset($input[$field]);
                }
            }
        }
        if (($input['create-db'] ?? null) !== 'yes') {
            unset($input['db-engine']);
        }
        if ($actor->owner !== null) {
            unset($input['owner']);
        }

        return $input;
    }

    /**
     * The route a form names, when the server lists it for the form's kind and it is one the server makes now.
     *
     * @param array<string, string> $raw
     * @param array<string, \Larena\Env\Server\SiteProfile>|null $server
     */
    public static function routeOf(array $raw, ?array $server): ?\Larena\Env\Server\SiteRoute
    {
        $route = isset($raw['route'], $raw['profile']) ? ($server[$raw['profile']] ?? null)?->route($raw['route']) : null;

        return $route !== null && $route->available ? $route : null;
    }

    /**
     * site:add's arguments of a route: the inputs site:add takes, the fixed arguments of the route's first step (the
     * Bitrix installer or restore.php) and, from the whole server only, the owner.
     *
     * @param array<string, string> $raw
     * @return array<string, string>
     */
    private static function routeInput(array $raw, PanelActor $actor, \Larena\Env\Server\SiteRoute $route): array
    {
        $input = ['profile' => $raw['profile']];
        $names = ['domain'];
        foreach ($route->inputs as $one) {
            if (in_array($one['kind'], \Larena\Env\Server\SiteRoute::ASKED, true) && PanelCommands::accepts('site:add', $one['name'])) {
                $names[] = $one['name'];
            }
        }
        foreach (array_unique($names) as $name) {
            $value = trim($raw[$name] ?? '');
            if ($value !== '') {
                $input[$name] = $value;
            }
        }
        $input += $route->addArguments();
        if (in_array('create-db', $names, true) && ($input['create-db'] ?? null) !== 'yes') {
            unset($input['db-engine']);
        }
        $owner = trim($raw['owner'] ?? '');
        if ($actor->owner === null && $owner !== '') {
            $input['owner'] = $owner;
        }

        return isset($input['domain']) ? ['domain' => $input['domain']] + $input : $input;
    }

    /**
     * The values of the route's later steps (a WordPress title, a repository): kept for that step, never sent to site:add.
     *
     * @param array<string, string> $raw
     * @return array<string, string>
     */
    public static function laterValues(array $raw, \Larena\Env\Server\SiteRoute $route): array
    {
        $values = [];
        foreach ($route->inputs as $one) {
            $value = trim($raw[$one['name']] ?? '');
            if ($value !== '' && in_array($one['kind'], \Larena\Env\Server\SiteRoute::ASKED, true) && !PanelCommands::accepts('site:add', $one['name'])
                && $route->stepTaking($one['name']) !== null) {
                $values[$one['name']] = $value;
            }
        }

        return $values;
    }

    /**
     * The first later-step input that is missing although required, or that its step's command would refuse; null when
     * they all would pass. Said under its name before site:add is planned.
     *
     * @param array<string, string> $raw
     */
    public static function wrongLater(array $raw, \Larena\Env\Server\SiteRoute $route): ?string
    {
        foreach ($route->inputs as $one) {
            $command = $route->stepTaking($one['name']);
            if ($command === null || PanelCommands::accepts('site:add', $one['name']) || !in_array($one['kind'], \Larena\Env\Server\SiteRoute::ASKED, true)) {
                continue;
            }
            $value = trim($raw[$one['name']] ?? '');
            if (($value === '' && $one['required']) || ($value !== '' && !PanelCommands::valid($command, $one['name'], $value))) {
                return $one['name'];
            }
        }

        return null;
    }

    /**
     * The ways a kind is made, each with its words, whether the panel can lead it now and why not, and its steps.
     *
     * @param callable(string): bool $allows
     * @return list<array{id: string, label: string, hint: string, available: bool, reason: ?string, steps: list<string>}>
     */
    private function routeChoices(string $kind, ?\Larena\Env\Server\SiteProfile $profile, callable $allows): array
    {
        $choices = [];
        foreach ($profile->routes ?? [] as $route) {
            $blocked = $route->blockedBy($allows);
            $choices[] = ['id' => $route->id, 'label' => $this->known('routes.names.'.$route->id, $route->title),
                'hint' => $this->known('routes.hints.'.$kind.'_'.$route->id, $route->title),
                'available' => $blocked === null, 'reason' => $blocked === null ? null : $this->text('routes.unavailable.'.$blocked),
                'steps' => array_map(fn (array $step): string => $this->stepLabel($step)
                    .(\Larena\Env\Server\SiteRoute::led($step, $allows) ? '' : ' — '.$this->text('routes.outside')), $route->steps)];
        }

        return $choices;
    }

    /**
     * The fields a route asks on the form: its texts, choices and addresses, each with the step that takes it.
     *
     * @return list<array{name: string, kind: string, label: string, required: bool, values: list<array{value: string, label: string}>, default: ?string, later: bool}>
     */
    private function routeFields(?\Larena\Env\Server\SiteRoute $route, \Larena\Env\Server\SiteProfile $profile, ?\Larena\Env\Server\ServerOverview $overview): array
    {
        $fields = [];
        foreach ($route->inputs ?? [] as $one) {
            if ($one['name'] === 'domain' || !in_array($one['kind'], \Larena\Env\Server\SiteRoute::ASKED, true)
                // An input of a step the panel does not lead (a Git repository) is given where that step is done.
                || (!PanelCommands::accepts('site:add', $one['name']) && $route->stepTaking($one['name']) === null)) {
                continue;
            }
            $values = $one['values'] ?? ($one['name'] === 'php' ? ($overview->phpVersions ?? []) : []);
            $fields[] = ['name' => $one['name'], 'kind' => $one['kind'] === 'choice' && $values !== [] ? 'choice' : $one['kind'], 'label' => $this->inputLabel($one['name'], $profile),
                'required' => $one['required'], 'default' => $one['default'],
                'values' => array_map(fn (string $value): array => ['value' => $value, 'label' => $this->known('routes.values.'.$value, $value)], $values),
                'later' => !PanelCommands::accepts('site:add', $one['name'])];
        }

        return $fields;
    }

    /**
     * The files a route puts on the server after the site is made (a backup archive, a dump): said on the form, asked
     * at their step on the site's card.
     *
     * @return list<string>
     */
    private function routeFiles(?\Larena\Env\Server\SiteRoute $route): array
    {
        $files = [];
        foreach ($route->inputs ?? [] as $one) {
            if ($one['kind'] === 'file_on_server') {
                $files[] = $this->inputLabel($one['name'], null);
            }
        }

        return $files;
    }

    /** @param array{command: string, url_from: ?string, title: string} $step */
    private function stepLabel(array $step): string
    {
        return $this->known('routes.steps.'.\Larena\Env\Admin\RouteWords::stepKey($step['command'], $step['url_from']), $step['title']);
    }

    private function inputLabel(string $name, ?\Larena\Env\Server\SiteProfile $profile): string
    {
        return $this->known('routes.inputs.'.\Larena\Env\Admin\RouteWords::inputKey($name), $name);
    }

    /** @param array<string, string> $replace */
    private function text(string $key, array $replace = []): string
    {
        return (string) $this->translator->get('larena-env::admin.'.$key, $replace);
    }

    private function known(string $key, string $fallback): string
    {
        $line = $this->text($key);

        return $line !== 'larena-env::admin.'.$key ? $line : $fallback;
    }

    /** @param array<string, string> $input */
    private function backWith(Request $request, string $text, array $input): RedirectResponse
    {
        return NoticeRedirect::to($request, route('larena.env.admin.sites.new', [], false).'?'.http_build_query($input), $text);
    }

    /**
     * The groups of fields a kind asks: from the server's own description of it where the server lists its kinds,
     * else what the panel knows of the six every server has.
     *
     * @param array<string, \Larena\Env\Server\SiteProfile>|null $server
     *
     * @return list<string>
     */
    /**
     * Whether a kind of site gets a daily backup: not a redirect, nor a kind the server says takes none (a proxy holds
     * no data of its own).
     *
     * @param array<string, \Larena\Env\Server\SiteProfile>|null $server
     */
    public static function backsUp(string $profile, ?array $server): bool
    {
        if ($server !== null && isset($server[$profile])) {
            return $server[$profile]->takes('backup');
        }

        return $profile !== 'redirect' && $profile !== 'proxy';
    }

    public static function groupsOf(string $profile, ?array $server): array
    {
        if ($server !== null) {
            $described = $server[$profile] ?? null;
            if ($described === null) {
                return [];
            }

            return array_values(array_filter(array_keys(self::FIELD_GROUPS), static fn (string $group): bool => array_filter(self::FIELD_GROUPS[$group],
                static fn (string $field): bool => $described->takes($field)) !== []));
        }

        return match (true) {
            in_array($profile, self::PHP_PROFILES, true) => ['php', 'database'],
            $profile === 'redirect' => ['redirect'],
            default => [],
        };
    }

    /**
     * The kinds this server makes, by name, when it lists them (self:profiles); null when it does not, or the list
     * cannot be read: the six every server has are offered then.
     *
     * @return array<string, \Larena\Env\Server\SiteProfile>|null
     */
    private function serverProfiles(PanelActor $actor, ?\Larena\Env\Server\ServerOverview $overview): ?array
    {
        if ($overview?->capabilities?->allows('self:profiles') !== true || $this->extras === null) {
            return null;
        }
        $profiles = $this->extras->profiles($actor);
        if ($profiles === null || $profiles === []) {
            return null;
        }
        $named = [];
        foreach ($profiles as $profile) {
            // Only the kinds the panel can send; a newer kind waits for a panel that knows its fields.
            if (in_array($profile->name, self::KNOWN_PROFILES, true)) {
                $named[$profile->name] = $profile;
            }
        }

        // In the panel's order, the simplest first, whatever order the server lists them in.
        uksort($named, static fn (string $a, string $b): int => array_search($a, self::KNOWN_PROFILES, true) <=> array_search($b, self::KNOWN_PROFILES, true));

        return $named === [] ? null : $named;
    }

    /**
     * What the wizard says of each of the server's kinds: the panel's words for one it knows, else its name and the
     * server's own line; and which fields it asks.
     *
     * @param array<string, \Larena\Env\Server\SiteProfile> $server
     *
     * @return array<string, array{label: string, hint: string, groups: list<string>, backup: bool}>
     */
    private function described(array $server): array
    {
        $described = [];
        foreach ($server as $name => $profile) {
            $known = in_array($name, self::KNOWN_PROFILES, true);
            $described[$name] = [
                'label' => $known ? (string) $this->translator->get('larena-env::admin.new_site.profiles.'.$name) : ($profile->title ?? $name),
                'hint' => $known ? (string) $this->translator->get('larena-env::admin.new_site.profile_hints.'.$name) : ($profile->description ?? ''),
                'groups' => self::groupsOf($name, $server),
                'backup' => self::backsUp($name, $server),
            ];
        }

        return $described;
    }

    /** @param array<string, string> $input */
    private function back(Request $request, string $reason, array $input): RedirectResponse
    {
        return NoticeRedirect::to($request, route('larena.env.admin.sites.new', [], false).'?'.http_build_query($input), $this->reasonText($reason));
    }

    public function reasonText(string $reason): string
    {
        $key = 'larena-env::admin.reasons.'.$reason;
        $line = (string) $this->translator->get($key);

        return $line !== $key ? $line : (string) $this->translator->get('larena-env::admin.reasons.unknown', ['reason' => $reason]);
    }
}
