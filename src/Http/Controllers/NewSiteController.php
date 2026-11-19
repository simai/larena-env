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

    /** The wizard's steps in order (owner decision dec-83b7d143b782: «Новый сайт» on sf-wizard, five steps). */
    public const array STEPS = ['kind', 'address', 'review', 'run', 'done'];
    /** The session key of the wizard: the values given so far and the run once it started. Never a password. */
    public const string WIZARD = 'larena_env.new_site_wizard';
    /** The commands of a kind's route the panel runs one after another in the same run, with no person between them. */
    public const array CHAINED = ['wp:install', 'wp:finalize', 'laravel:finalize', 'site:deploy'];
    /** The languages WordPress is offered in, by name (PX-20): the code is the server's. */
    public const array LOCALES = ['ru_RU', 'en_US', 'de_DE', 'fr_FR', 'es_ES', 'it_IT', 'uk', 'kk'];

    /** The wizard's first step: the kind of site (GET /sites/new). */
    public function create(Request $request): mixed
    {
        return $this->step($request, 'kind');
    }

    /**
     * One step of the wizard on its own page (sf-wizard place="pages"): the kind, the address and settings, the server's
     * plan in words, the run with its progress, the result. A step whose earlier ones are not there goes back to them.
     */
    public function step(Request $request, string $step): mixed
    {
        $actor = ResolvePanelActor::of($request);
        $overview = $this->overviews->read($actor)->overview;
        $server = $this->serverProfiles($actor, $overview);
        $state = $this->wizardState($request);
        $values = $state['values'];
        $run = $state['run'];
        // A kind asked in the address (a link from «Первые шаги» or after a kind was switched on) is the chosen one.
        if ($step === 'kind' && is_string($request->query('profile'))) {
            $values['profile'] = (string) $request->query('profile');
        }
        $offer = $this->kinds($actor, $overview, $server);
        $kind = in_array($values['profile'] ?? null, $offer['profiles'], true) && !isset($offer['unavailable'][$values['profile']])
            ? (string) $values['profile'] : ($offer['open'][0] ?? 'static');
        $required = match ($step) {
            'address' => isset($values['profile']),
            'review' => isset($values['profile'], $values['domain']),
            'run', 'done' => $run !== null,
            default => true,
        };
        if (!$required) {
            return new RedirectResponse(route('larena.env.admin.sites.new', [], false));
        }
        $allows = static fn (string $command): bool => $overview?->capabilities?->allows($command) === true;
        $route = null;
        $routes = null;
        if ($server !== null && isset($server[$kind]) && $server[$kind]->routes !== []) {
            $routes = array_values(array_filter($this->routeChoices($kind, $server[$kind], $allows), static fn (array $one): bool => $one['available']));
            $asked = $step === 'address' && is_string($request->query('route')) ? (string) $request->query('route') : ($values['route'] ?? null);
            foreach ($routes as $one) {
                if ($one['id'] === $asked) {
                    $route = $one['id'];
                }
            }
            $route ??= $routes[0]['id'] ?? null;
        }
        $data = [
            'step' => $step,
            'kind' => $kind,
            'kinds' => $offer['items'],
            'disabled' => $offer['disabled'],
            'routes' => $routes,
            'route' => $route,
            'values' => $values,
            'errors' => $request->hasSession() && is_array($request->session()->get(self::WIZARD.'_errors')) ? $request->session()->get(self::WIZARD.'_errors') : [],
            'notice' => $request->session()->get('larena_env_notice'),
            'states' => self::stepStates($step, $values, $run),
            'canEnable' => $actor->owner === null && $allows('profile:enable'),
            'serverIp' => $this->extras?->serverIp($actor, $overview?->capabilities),
            'message' => null,
            'stepState' => '',
            'refresh' => false,
        ];
        if ($step === 'address') {
            $data['fields'] = $this->wizardFields($request, $actor, $overview, $server, $kind, $route, $values);
        } elseif ($step === 'review') {
            [$result, $input, $chain] = $this->wizardPlan($actor, $overview, $server, $values);
            if ($result->plan === null) {
                return $this->toStep($request, 'address', ['domain' => $this->reasonText($result->reason ?? 'answer_shape_invalid')]);
            }
            $data['facts'] = $this->wizardFacts($values, $input, $chain, $server, $result->plan);
            $data['plan'] = $result->plan;
            $data['then'] = $this->laterSteps($server, $values);
        } elseif ($step === 'run') {
            $run = $this->advance($request, $actor, $run);
            $data += $this->runView($run);
            $data['stepState'] = $data['runState'] === 'failed' ? 'error' : ($data['runState'] === 'done' ? 'done' : 'running');
            $data['message'] = $data['runMessage'];
            $data['refresh'] = $data['runState'] === 'running';
            $data['states'] = self::stepStates($step, $values, $run);
        } elseif ($step === 'done') {
            $data += $this->doneView($actor, $run, $server, $values);
        }

        $page = $this->views->make('larena-env::admin.new-site', $data);

        // A run still under way: the browser asks again in a few seconds (no script of the panel's own); a value given
        // once (WordPress's password) is never kept by the browser or a proxy.
        return match (true) {
            $data['refresh'] => new \Illuminate\Http\Response($page->render(), 200, ['Refresh' => '3', 'Cache-Control' => 'no-store, private']),
            $step === 'done' => new \Illuminate\Http\Response($page->render(), 200, ['Cache-Control' => 'no-store, private', 'Pragma' => 'no-cache']),
            default => $page,
        };
    }

    /** A step's answer (the wizard's form): the kind, the fields, the confirmation, a retry, the end. */
    public function submit(Request $request, string $step): RedirectResponse
    {
        $actor = ResolvePanelActor::of($request);
        $overview = $this->overviews->read($actor)->overview;
        $server = $this->serverProfiles($actor, $overview);
        $state = $this->wizardState($request);
        $values = $state['values'];
        $raw = self::strings($request->except(['_token', 'wizard-action']));
        $action = (string) $request->input('wizard-action', 'next');
        switch ($step) {
            case 'kind':
                $offer = $this->kinds($actor, $overview, $server);
                $profile = $raw['profile'] ?? '';
                if (!in_array($profile, $offer['open'], true)) {
                    return $this->toStep($request, 'kind', ['profile' => $this->text('new_site.invalid.profile')]);
                }
                // Another kind asks other fields: what was given for the former one goes.
                $values = ($values['profile'] ?? null) === $profile ? $values : ['profile' => $profile];
                $this->keepWizard($request, $values, null);

                return $this->toStep($request, 'address');
            case 'address':
                if (!isset($values['profile'])) {
                    return $this->toStep($request, 'kind');
                }
                $values = ['profile' => $values['profile']] + $raw;
                // A Framework box sends its value only when ticked: missing is «no».
                $ticked = static fn (string $name): string => in_array($raw[$name] ?? '', ['yes', 'on', '1', 'true'], true) ? 'yes' : 'no';
                $values['daily-backup'] = $ticked('daily-backup');
                if (isset($raw['create-db']) || $this->dbOf($server, $values['profile']) === 'optional') {
                    $values['create-db'] = $ticked('create-db');
                }
                $this->keepWizard($request, $values, null);
                $wrong = $this->wrongField($values, $actor, $server);
                // A domain the server already has a site on is said at its field, not after a failed run.
                foreach ($overview->sites ?? [] as $site) {
                    if ($wrong === null && strcasecmp($site->domain, trim($values['domain'] ?? '')) === 0) {
                        $wrong = ['domain', $this->text('new_site.invalid.domain_taken')];
                    }
                }
                if ($wrong !== null) {
                    return $this->toStep($request, 'address', [$wrong[0] => $wrong[1]]);
                }
                [$result] = $this->wizardPlan($actor, $overview, $server, $values);
                if ($result->plan === null) {
                    return $this->toStep($request, 'address', ['domain' => $this->reasonText($result->reason ?? 'answer_shape_invalid')]);
                }

                return $this->toStep($request, 'review');
            case 'review':
                if (!isset($values['profile'], $values['domain'])) {
                    return $this->toStep($request, 'kind');
                }
                [, $input, $chain] = $this->wizardPlan($actor, $overview, $server, $values);
                $started = $this->operations->startCommand($actor, 'site:add', $input);
                if ($started->jobId === null) {
                    return $this->toStep($request, 'address', ['domain' => $this->reasonText($started->reason ?? 'answer_shape_invalid')]);
                }
                $chain[0]['job'] = $started->jobId;
                $this->keepWizard($request, $values, ['domain' => $input['domain'], 'steps' => $chain, 'at' => time()]);
                if ($route = self::routeOf($values, $server)) {
                    // What the later steps of the route take (a repository), offered again on the site's card at that step.
                    $request->session()->put(self::ROUTE_VALUES.'.'.strtolower($input['domain']), ['route' => $route->id] + self::laterValues($values, $route));
                }

                return $this->toStep($request, 'run');
            case 'run':
                $run = $state['run'];
                if ($run === null) {
                    return $this->toStep($request, 'kind');
                }
                if ($action === 'retry') {
                    foreach ($run['steps'] as $index => $one) {
                        if (($one['state'] ?? '') === 'failed') {
                            $started = $this->operations->startCommand($actor, $one['command'], $one['args']);
                            $run['steps'][$index] = ['job' => $started->jobId, 'state' => $started->jobId === null ? 'failed' : 'running'] + $one;
                            break;
                        }
                    }
                    $this->keepWizard($request, $values, $run);

                    return $this->toStep($request, 'run');
                }

                return $this->toStep($request, self::runOver($run) ? 'done' : 'run');
            default:
                // The end: the site's card, and the wizard is over.
                $domain = $state['run']['domain'] ?? null;
                $request->session()->forget(self::WIZARD);

                return new RedirectResponse(is_string($domain) ? route('larena.env.admin.site', ['domain' => $domain], false) : route('larena.env.admin.index', [], false));
        }
    }

    /**
     * «Включить» of a switched-off kind (simai-env 1.57.5, profile:enable): its plan as the admin's «Действие с планом» in
     * the side panel over the wizard; only the whole server's administrator.
     */
    public function enablePlan(Request $request): mixed
    {
        $actor = ResolvePanelActor::of($request);
        $id = (string) $request->input('id', '');
        $name = $this->extras?->disabledProfiles($actor)[$id] ?? null;
        if ($actor->owner !== null || $name === null) {
            return NoticeRedirect::to($request, route('larena.env.admin.sites.new', [], false), $this->reasonText('not_allowed'));
        }
        $result = $this->operations->planCommand($actor, 'profile:enable', ['id' => $id]);
        if ($result->plan === null) {
            return NoticeRedirect::to($request, route('larena.env.admin.sites.new', [], false), $this->reasonText($result->reason ?? 'answer_shape_invalid'));
        }
        $label = $this->known('new_site.profiles.'.$id, $name);

        return $this->views->make('larena-env::admin.plan', [
            'heading' => $this->text('new_site.wizard.enable_heading', ['name' => $label]),
            'eyebrow' => $label,
            'target' => '',
            'source' => $result->source,
            'plan' => $result->plan,
            'runUrl' => route('larena.env.admin.sites.enable.run', [], false),
            'cancelUrl' => route('larena.env.admin.sites.new', [], false),
            'hidden' => ['id' => $id],
        ]);
    }

    public function enableRun(Request $request): RedirectResponse
    {
        $actor = ResolvePanelActor::of($request);
        $id = (string) $request->input('id', '');
        if ($actor->owner !== null || !isset($this->extras?->disabledProfiles($actor)[$id])) {
            return NoticeRedirect::to($request, route('larena.env.admin.sites.new', [], false), $this->reasonText('not_allowed'));
        }
        $started = $this->operations->startCommand($actor, 'profile:enable', ['id' => $id]);
        if ($started->jobId === null) {
            return NoticeRedirect::to($request, route('larena.env.admin.sites.new', [], false), $this->reasonText($started->reason ?? 'answer_shape_invalid'));
        }

        return new RedirectResponse(route('larena.env.admin.job', ['job' => $started->jobId], false));
    }

    /**
     * The kinds as the first step offers them: each as a tile (WordPress first, «чаще всего»), the ones the panel cannot
     * lead here with the reason, and the switched-off ones for «Включить».
     *
     * @param array<string, \Larena\Env\Server\SiteProfile>|null $server
     * @return array{profiles: list<string>, open: list<string>, unavailable: array<string, string>, items: list<array<string, mixed>>, disabled: array<string, string>}
     */
    private function kinds(PanelActor $actor, ?\Larena\Env\Server\ServerOverview $overview, ?array $server): array
    {
        $profiles = $server === null ? self::PROFILES : array_keys($server);
        if (in_array('wordpress', $profiles, true)) {
            $profiles = ['wordpress', ...array_values(array_diff($profiles, ['wordpress']))];
        }
        $allows = static fn (string $command): bool => $overview?->capabilities?->allows($command) === true;
        $unavailable = [];
        foreach ($server ?? [] as $name => $profile) {
            if ($profile->routes !== [] && array_filter($profile->routes, static fn (\Larena\Env\Server\SiteRoute $one): bool => $one->blockedBy($allows) === null) === []) {
                $unavailable[$name] = $this->text('routes.unavailable.profile');
            }
        }
        $described = $server === null ? null : $this->described($server);
        $items = [];
        foreach ($profiles as $name) {
            $item = ['value' => $name, 'label' => $described[$name]['label'] ?? $this->text('new_site.profiles.'.$name),
                'caption' => $unavailable[$name] ?? ($described[$name]['hint'] ?? $this->text('new_site.profile_hints.'.$name))];
            if ($name === 'wordpress') {
                $item['badge'] = $this->text('new_site.wizard.often');
            }
            if (isset($unavailable[$name])) {
                $item['disabled'] = true;
            }
            $items[] = $item;
        }
        $disabled = [];
        if ($actor->owner === null && $allows('profile:enable')) {
            foreach ($this->extras?->disabledProfiles($actor) ?? [] as $id => $title) {
                if (in_array($id, self::KNOWN_PROFILES, true)) {
                    $disabled[$id] = $this->known('new_site.profiles.'.$id, $title);
                }
            }
        }

        return ['profiles' => $profiles, 'open' => array_values(array_diff($profiles, array_keys($unavailable))), 'unavailable' => $unavailable,
            'items' => $items, 'disabled' => $disabled];
    }

    /**
     * The address step's fields: only what the kind and its way take, by the Framework's fields. The database said in
     * words where the kind always has one, a switch where it may; the language by name; whose site only to the whole
     * server, with why.
     *
     * @param array<string, \Larena\Env\Server\SiteProfile>|null $server
     * @param array<string, string> $values
     * @return list<array<string, mixed>>
     */
    private function wizardFields(Request $request, PanelActor $actor, ?\Larena\Env\Server\ServerOverview $overview, ?array $server, string $kind, ?string $routeId, array $values): array
    {
        $fields = [['name' => 'domain', 'kind' => 'text', 'label' => $this->text('new_site.domain'), 'required' => true, 'value' => $values['domain'] ?? '',
            'placeholder' => 'example.com', 'hint' => $this->text('new_site.domain_hint')]];
        $profile = $server[$kind] ?? null;
        $route = $routeId === null ? null : $profile?->route($routeId);
        $php = $overview->phpVersions ?? [];
        $choice = fn (array $list, ?callable $word = null): array => array_map(fn (string $value): array => ['value' => $value, 'text' => $word === null ? $value : $word($value)], $list);
        $db = $this->dbOf($server, $kind);
        if ($route !== null) {
            foreach ($this->routeFields($route, $profile, $overview) as $field) {
                if (in_array($field['name'], ['create-db', 'db-engine'], true)) {
                    continue;
                }
                $one = ['name' => $field['name'], 'kind' => $field['kind'] === 'choice' ? 'choice' : ($field['name'] === 'admin-email' ? 'email' : 'text'),
                    'label' => $field['label'], 'required' => $field['required'], 'value' => $values[$field['name']] ?? ($field['default'] ?? ''),
                    'options' => array_map(static fn (array $value): array => ['value' => $value['value'], 'text' => $value['label']], $field['values'])];
                if ($field['name'] === 'locale') {
                    $one = ['kind' => 'choice', 'label' => $this->text('new_site.wizard.locale'), 'options' => $choice(self::LOCALES, fn (string $code): string => $this->text('new_site.wizard.locales.'.$code)),
                        'value' => $values['locale'] ?? (str_starts_with($this->translator->getLocale(), 'ru') ? 'ru_RU' : 'en_US')] + $one;
                }
                if ($field['name'] === 'admin-email' && $one['value'] === '') {
                    $one['value'] = (string) SiteAdminController::personEmail($request);
                }
                $fields[] = $one;
            }
            // A file the way puts on the server after the site is made (a backup archive): said now, asked at its step on the card.
            foreach ($this->routeFiles($route) as $index => $file) {
                $fields[] = ['name' => 'file-'.$index, 'kind' => 'note', 'label' => '', 'value' => $this->text('routes.file_later', ['file' => $file])];
            }
        } else {
            $groups = self::groupsOf($kind, $server);
            if (in_array('php', $groups, true)) {
                $fields[] = ['name' => 'php', 'kind' => 'choice', 'label' => $this->text('new_site.php'), 'value' => $values['php'] ?? ($php[0] ?? ''), 'options' => $choice($php)];
            }
            if (in_array('redirect', $groups, true)) {
                $fields[] = ['name' => 'redirect-to', 'kind' => 'text', 'label' => $this->text('new_site.redirect_to'), 'required' => true, 'value' => $values['redirect-to'] ?? '', 'placeholder' => 'https://example.org'];
            }
            if (in_array('proxy', $groups, true)) {
                $fields[] = ['name' => 'upstream-port', 'kind' => 'text', 'label' => $this->text('new_site.upstream_port'), 'required' => true, 'value' => $values['upstream-port'] ?? '', 'hint' => $this->text('new_site.upstream_port_hint')];
                $fields[] = ['name' => 'upstream-path', 'kind' => 'text', 'label' => $this->text('new_site.upstream_path'), 'value' => $values['upstream-path'] ?? ''];
            }
            if (in_array('bitrix', $groups, true)) {
                $fields[] = ['name' => 'bitrix-files', 'kind' => 'choice', 'label' => $this->text('new_site.bitrix_files'), 'value' => $values['bitrix-files'] ?? 'none',
                    'options' => $choice(self::BITRIX_FILES, fn (string $value): string => $this->text('new_site.bitrix_files_choices.'.$value))];
            }
            if ($db === 'no' && in_array('database', $groups, true)) {
                $db = 'optional';
            }
        }
        // The database (PX of ENV 2026-11-18): in words where the kind always has one, a switch where it may.
        if ($db === 'required') {
            $fields[] = ['name' => 'db-note', 'kind' => 'note', 'label' => '', 'value' => $this->text('new_site.wizard.db_required')];
        } elseif ($db === 'optional') {
            // On by the server's default for the kind (self:profiles `create-db`, «yes» for a PHP site), off where it says no.
            $dbDefault = $server[$kind]->args['create-db']['default'] ?? 'yes';
            $fields[] = ['name' => 'create-db', 'kind' => 'switch', 'label' => $this->text('new_site.wizard.db_optional'), 'value' => ($values['create-db'] ?? $dbDefault) === 'yes' ? 'yes' : '',
                'hint' => $this->text('new_site.wizard.db_optional_hint')];
        }
        if ($this->schedules($overview) && self::backsUp($kind, $server)) {
            $fields[] = ['name' => 'daily-backup', 'kind' => 'check', 'label' => $this->text('new_site.daily_backup'), 'value' => ($values['daily-backup'] ?? 'yes') === 'yes' ? 'yes' : '',
                'hint' => $this->text('new_site.daily_backup_hint')];
        }
        $owners = $actor->owner === null ? ($overview->owners ?? []) : null;
        if ($owners !== null && $owners !== []) {
            $fields[] = ['name' => 'owner', 'kind' => 'choice', 'label' => $this->text('new_site.owner'), 'value' => $values['owner'] ?? '',
                'options' => array_merge([['value' => '', 'text' => $this->text('new_site.own_account')]], $choice($owners)),
                'hint' => $this->text('new_site.owner_hint').' '.$this->text('new_site.wizard.owner_system')];
        }

        return $fields;
    }

    /**
     * site:add's plan for the wizard's values, its arguments (the certificate in the same job where the server takes it and
     * the domain already leads here) and the run's steps: site:add, then each step of the kind's way the panel runs with no
     * person between them, the certificate where it is a job of its own, and the nightly copy on an older server.
     *
     * @param array<string, \Larena\Env\Server\SiteProfile>|null $server
     * @param array<string, string> $values
     * @return array{0: \Larena\Env\Operations\PlanResult, 1: array<string, string>, 2: list<array{id: string, command: string, args: array<string, string>, job: ?string, state: string}>}
     */
    private function wizardPlan(PanelActor $actor, ?\Larena\Env\Server\ServerOverview $overview, ?array $server, array $values): array
    {
        $capabilities = $overview?->capabilities;
        $input = self::withBackup(self::input($values, $actor, $server), $values, $overview, $server);
        $domain = $input['domain'] ?? '';
        $route = self::routeOf($values, $server);
        $email = $values['admin-email'] ?? ($values['ssl-email'] ?? '');
        $ssl = $domain !== '' && $this->pointsHere($domain, $actor, $overview) && PanelCommands::valid('site:add', 'ssl-email', $email);
        $chain = [];
        if ($ssl && $capabilities?->takes('site:add', 'ssl') === true) {
            $input += ['ssl' => 'yes', 'ssl-email' => $email, 'ssl-redirect' => 'yes'];
        }
        $chain[] = ['id' => 'site', 'command' => 'site:add', 'args' => $input, 'job' => null, 'state' => 'waiting'];
        $later = $route === null ? [] : self::laterValues($values, $route);
        foreach ($route->steps ?? [] as $step) {
            if ($step['command'] === 'site:add') {
                continue;
            }
            if (!in_array($step['command'], self::CHAINED, true) || $capabilities?->allows($step['command']) !== true) {
                break;
            }
            $args = ['domain' => $domain] + $step['args'];
            foreach ($later as $name => $value) {
                if (PanelCommands::accepts($step['command'], $name)) {
                    $args[$name] = $value;
                }
            }
            $chain[] = ['id' => str_replace(':', '-', $step['command']), 'command' => $step['command'], 'args' => $args, 'job' => null, 'state' => 'waiting'];
        }
        if ($ssl && !isset($input['ssl']) && $capabilities?->allows('ssl:letsencrypt') === true) {
            $chain[] = ['id' => 'ssl', 'command' => 'ssl:letsencrypt', 'args' => ['domain' => $domain, 'email' => $email, 'redirect' => 'yes'], 'job' => null, 'state' => 'waiting'];
        }
        if ($this->followsWithSchedule($values, $overview, $input, $server)) {
            $chain[] = ['id' => 'backup', 'command' => 'backup:data-schedule', 'args' => ['domain' => $domain, 'enabled' => 'yes'], 'job' => null, 'state' => 'waiting'];
        }

        return [$this->operations->planCommand($actor, 'site:add', $input), $input, $chain];
    }

    /**
     * Whether the domain already leads to this server: then HTTPS comes in the same run (the prototype's rule). Asked of
     * the resolver once per wizard step; a domain that does not resolve, or the server's address unknown, is «not yet».
     */
    private function pointsHere(string $domain, PanelActor $actor, ?\Larena\Env\Server\ServerOverview $overview): bool
    {
        $ip = $this->extras?->serverIp($actor, $overview?->capabilities);
        if ($ip === null || !str_contains($domain, '.') || str_ends_with($domain, '.test') || str_ends_with($domain, '.localhost')) {
            return false;
        }
        $found = @gethostbynamel($domain.'.');

        return is_array($found) && in_array($ip, $found, true);
    }

    /**
     * The plan in the panel's words: the site, its database, WordPress, HTTPS, copies and whose it is, then what the
     * server says it needs.
     *
     * @param array<string, string> $values
     * @param array<string, string> $input
     * @param list<array<string, mixed>> $chain
     * @param array<string, \Larena\Env\Server\SiteProfile>|null $server
     * @return list<array{label: string, value: string, step: string}>
     */
    private function wizardFacts(array $values, array $input, array $chain, ?array $server, \Larena\Env\Operations\PanelPlan $plan): array
    {
        $kind = $values['profile'] ?? 'static';
        $label = $this->described($server ?? [])[$kind]['label'] ?? $this->text('new_site.profiles.'.$kind);
        $facts = [['label' => $this->text('new_site.wizard.facts.site'), 'value' => trim(($input['domain'] ?? '').', '.$label.(isset($input['php']) ? ', PHP '.$input['php'] : ''), ', '), 'step' => 'address']];
        $db = $this->dbOf($server, $kind);
        if ($db !== 'no' || isset($input['create-db'])) {
            $facts[] = ['label' => $this->text('new_site.wizard.facts.database'), 'value' => $this->text('new_site.wizard.facts.'.(($db === 'required' || ($input['create-db'] ?? 'no') === 'yes') ? 'database_new' : 'database_none')), 'step' => 'address'];
        }
        foreach ($chain as $one) {
            if ($one['command'] === 'wp:install') {
                $facts[] = ['label' => $this->text('new_site.wizard.facts.wordpress'), 'value' => $this->text('new_site.wizard.facts.wordpress_value', ['title' => $one['args']['title'] ?? '',
                    'locale' => $this->known('new_site.wizard.locales.'.($one['args']['locale'] ?? ''), $one['args']['locale'] ?? '—'), 'user' => $one['args']['admin-user'] ?? '']), 'step' => 'address'];
            }
        }
        $ssl = isset($input['ssl']) || in_array('ssl:letsencrypt', array_column($chain, 'command'), true);
        $facts[] = ['label' => $this->text('new_site.wizard.facts.https'), 'value' => $this->text('new_site.wizard.facts.'.($ssl ? 'https_now' : 'https_later')), 'step' => 'address'];
        if (self::backsUp($kind, $server)) {
            $facts[] = ['label' => $this->text('new_site.wizard.facts.backup'), 'value' => $this->text('new_site.wizard.facts.'.(self::dailyBackup($values) ? 'backup_on' : 'backup_off')), 'step' => 'address'];
        }
        if (isset($input['owner'])) {
            $facts[] = ['label' => $this->text('new_site.wizard.facts.owner'), 'value' => $input['owner'], 'step' => 'address'];
        }
        $facts[] = ['label' => $this->text('new_site.wizard.facts.steps'), 'value' => implode(' → ', array_map(fn (array $one): string => $this->runStepLabel($one, $input), $chain)), 'step' => ''];
        $resources = $plan->resources;
        if ($resources !== null && $resources->ramMb !== null) {
            $facts[] = ['label' => $this->text('routes.resources.ram'), 'value' => $this->text('routes.resources.ram_value', ['have' => $resources->ramMb.' MB',
                'min' => $resources->ramMinMb.' MB', 'recommended' => $resources->ramRecommendedMb.' MB']), 'step' => ''];
        }
        if ($resources !== null && $resources->diskFreeBytes !== null) {
            $facts[] = ['label' => $this->text('routes.resources.disk'), 'value' => $this->text('routes.resources.disk_free', ['free' => \Larena\Env\Server\Bytes::human($resources->diskFreeBytes)]), 'step' => ''];
        }

        return $facts;
    }

    /**
     * The steps of the kind's way that stay for the site's card (the installer in the browser, the files of a copy).
     *
     * @param array<string, \Larena\Env\Server\SiteProfile>|null $server
     * @param array<string, string> $values
     * @return list<string>
     */
    private function laterSteps(?array $server, array $values): array
    {
        $route = self::routeOf($values, $server);
        $later = [];
        $chained = true;
        foreach ($route->steps ?? [] as $step) {
            if ($step['command'] === 'site:add') {
                continue;
            }
            $chained = $chained && in_array($step['command'], self::CHAINED, true);
            if (!$chained && $step['command'] !== 'site:doctor') {
                $later[] = $this->stepLabel($step);
            }
        }

        return $later;
    }

    /**
     * The run as the server has it now: each step's job read from the server's list of jobs (job:list: never a value
     * given once), the next started once the one before it succeeded. Kept in the session.
     *
     * @param array{domain: string, steps: list<array<string, mixed>>, at: int} $run
     * @return array{domain: string, steps: list<array<string, mixed>>, at: int}
     */
    private function advance(Request $request, PanelActor $actor, array $run): array
    {
        if (self::runOver($run)) {
            return $run;
        }
        $jobs = $this->operations->jobs($actor) ?? [];
        foreach ($run['steps'] as $index => $one) {
            if (in_array($one['state'], ['done', 'failed'], true)) {
                if ($one['state'] === 'failed') {
                    break;
                }
                continue;
            }
            if ($one['job'] === null) {
                $started = $this->operations->startCommand($actor, $one['command'], $one['args']);
                $run['steps'][$index]['job'] = $started->jobId;
                $run['steps'][$index]['state'] = $started->jobId === null ? 'failed' : 'running';
                $run['steps'][$index]['reason'] = $started->jobId === null ? ($started->reason ?? 'answer_shape_invalid') : null;
                $run['steps'][$index]['started'] = gmdate('Y-m-d\TH:i:s\Z');
                break;
            }
            $job = $jobs[$one['job']] ?? null;
            if ($job === null || !$job->state->isOver()) {
                $run['steps'][$index]['state'] = 'running';
                $run['steps'][$index]['started'] ??= $job?->createdAt;
                break;
            }
            $run['steps'][$index]['state'] = $job->state === \Larena\Env\Operations\JobState::Succeeded ? 'done' : 'failed';
            $run['steps'][$index]['finished'] = gmdate('Y-m-d\TH:i:s\Z');
            if ($run['steps'][$index]['state'] === 'failed') {
                break;
            }
            // The next step starts in this same answer, so the run does not wait a refresh between them.
            $next = $run['steps'][$index + 1] ?? null;
            if ($next !== null && $next['job'] === null) {
                $started = $this->operations->startCommand($actor, $next['command'], $next['args']);
                $run['steps'][$index + 1]['job'] = $started->jobId;
                $run['steps'][$index + 1]['state'] = $started->jobId === null ? 'failed' : 'running';
                $run['steps'][$index + 1]['reason'] = $started->jobId === null ? ($started->reason ?? 'answer_shape_invalid') : null;
                $run['steps'][$index + 1]['started'] = gmdate('Y-m-d\TH:i:s\Z');
                break;
            }
        }
        $state = $this->wizardState($request);
        $this->keepWizard($request, $state['values'], $run);

        return $run;
    }

    /**
     * What the run step draws: the Framework's sf-progress-run (its steps, state and words), the step's message and the
     * job of a failed step for its log.
     *
     * @param array{domain: string, steps: list<array<string, mixed>>, at: int} $run
     * @return array<string, mixed>
     */
    private function runView(array $run): array
    {
        $steps = [];
        $failed = null;
        foreach ($run['steps'] as $one) {
            $item = ['id' => $one['id'], 'title' => $this->runStepLabel($one, $run['steps'][0]['args']), 'state' => $one['state'] === 'waiting' ? 'waiting' : $one['state']];
            if (is_string($one['started'] ?? null)) {
                $item['started'] = $one['started'];
            }
            if (is_string($one['finished'] ?? null)) {
                $item['finished'] = $one['finished'];
            }
            if ($one['state'] === 'failed') {
                $failed ??= $one;
            }
            $steps[] = $item;
        }
        $over = self::runOver($run);
        $state = $failed !== null ? 'failed' : ($over ? 'done' : 'running');

        return [
            'runSteps' => $steps,
            'runState' => $state,
            'runLabel' => $this->text('new_site.wizard.run_label', ['domain' => $run['domain']]),
            'runResult' => $state === 'done' ? $this->text('new_site.wizard.run_done') : ($failed !== null ? $this->text('new_site.wizard.run_failed', ['step' => $this->runStepLabel($failed, $run['steps'][0]['args'])]) : ''),
            'runMessage' => $failed !== null ? $this->text('new_site.wizard.run_failed', ['step' => $this->runStepLabel($failed, $run['steps'][0]['args'])])
                .(is_string($failed['reason'] ?? null) ? ' '.$this->reasonText($failed['reason']) : '') : null,
            'runFailedJob' => is_string($failed['job'] ?? null) ? $failed['job'] : null,
            'runStarted' => gmdate('Y-m-d\TH:i:s\Z', $run['at']),
        ];
    }

    /**
     * The result: the site's addresses and, after WordPress, its administrator's password — read here once from the
     * server (the first job:status after the install gives it, and no later one), shown on this answer only.
     *
     * @param array{domain: string, steps: list<array<string, mixed>>, at: int} $run
     * @param array<string, \Larena\Env\Server\SiteProfile>|null $server
     * @param array<string, string> $values
     * @return array<string, mixed>
     */
    private function doneView(PanelActor $actor, array $run, ?array $server, array $values): array
    {
        $secret = null;
        $shown = false;
        foreach ($run['steps'] as $one) {
            if ($one['command'] === 'wp:install' && is_string($one['job'] ?? null) && $one['state'] === 'done') {
                $status = $this->operations->view($actor, $one['job'])->status;
                $secret = $status?->secret;
                $shown = $secret === null && in_array('admin_password', $status->shownOnce ?? [], true);
            }
        }
        $https = isset($run['steps'][0]['args']['ssl']) || in_array('ssl:letsencrypt', array_column($run['steps'], 'command'), true);

        return [
            'domain' => $run['domain'],
            'secret' => $secret,
            'secretShown' => $shown,
            'siteUrl' => ($https ? 'https://' : 'http://').$run['domain'].'/',
            'wpLogin' => $secret->loginUrl ?? (in_array('wp:install', array_column($run['steps'], 'command'), true) ? ($https ? 'https://' : 'http://').$run['domain'].'/wp-login.php' : null),
            'cardUrl' => route('larena.env.admin.site', ['domain' => $run['domain']], false),
            'then' => $this->laterSteps($server, $values),
            'over' => self::runOver($run),
        ];
    }

    /** @param array<string, mixed> $one @param array<string, string> $add site:add's arguments */
    private function runStepLabel(array $one, array $add): string
    {
        return match ($one['command']) {
            'site:add' => $this->text('new_site.wizard.run_steps.'.(isset($add['ssl']) ? 'site_ssl' : ((($add['create-db'] ?? null) === 'yes' || in_array($add['profile'] ?? '', ['wordpress', 'laravel', 'bitrix'], true)) ? 'site_db' : 'site'))),
            'ssl:letsencrypt' => $this->text('new_site.wizard.run_steps.ssl'),
            'backup:data-schedule' => $this->text('new_site.wizard.run_steps.backup'),
            default => $this->stepLabel(['command' => $one['command'], 'url_from' => null, 'title' => $one['command']]),
        };
    }

    /** @param array{steps: list<array<string, mixed>>} $run */
    private static function runOver(array $run): bool
    {
        foreach ($run['steps'] as $one) {
            if ($one['state'] !== 'done') {
                return false;
            }
        }

        return true;
    }

    /**
     * Each step's mark in the wizard's progress: the ones behind the current done, a run's step by how it goes.
     *
     * @param array<string, string> $values
     * @param array<string, mixed>|null $run
     * @return array<string, string>
     */
    private static function stepStates(string $step, array $values, ?array $run): array
    {
        $states = [];
        $at = array_search($step, self::STEPS, true);
        foreach (self::STEPS as $index => $name) {
            $states[$name] = $index < $at ? 'done' : '';
        }
        if ($run !== null) {
            $states['kind'] = $states['address'] = $states['review'] = 'done';
            $states['run'] = self::runOver($run) ? 'done' : '';
        }

        return $states;
    }

    /**
     * The first field the gateway would refuse, with the words to say under it; null when they all pass.
     *
     * @param array<string, string> $values
     * @param array<string, \Larena\Env\Server\SiteProfile>|null $server
     * @return array{0: string, 1: string}|null
     */
    private function wrongField(array $values, PanelActor $actor, ?array $server): ?array
    {
        $domain = trim($values['domain'] ?? '');
        if ($domain === '' || !PanelCommands::valid('site:add', 'domain', $domain)) {
            return ['domain', $this->text('new_site.invalid.domain')];
        }
        $route = self::routeOf($values, $server);
        $later = $route === null ? null : self::wrongLater($values, $route);
        if ($later !== null) {
            return [$later, $this->text('routes.invalid', ['field' => $this->inputLabel($later, null)])];
        }
        foreach (self::input($values, $actor, $server) as $name => $value) {
            if (PanelCommands::accepts('site:add', $name) && !PanelCommands::valid('site:add', $name, $value)) {
                return [$name, $this->text('routes.invalid', ['field' => $this->inputLabel($name, null)])];
            }
        }

        return null;
    }

    /** @param array<string, \Larena\Env\Server\SiteProfile>|null $server the kind's database: no, optional or required */
    private function dbOf(?array $server, string $kind): string
    {
        if ($server !== null && isset($server[$kind])) {
            return $server[$kind]->db;
        }

        return match ($kind) {'wordpress', 'laravel' => 'required', 'generic' => 'optional', default => 'no'};
    }

    /** @return array{values: array<string, string>, run: ?array{domain: string, steps: list<array<string, mixed>>, at: int}} */
    private function wizardState(Request $request): array
    {
        $kept = $request->hasSession() ? $request->session()->get(self::WIZARD) : null;
        $values = is_array($kept['values'] ?? null) ? self::strings($kept['values']) : [];
        $run = is_array($kept['run'] ?? null) && is_string($kept['run']['domain'] ?? null) && is_array($kept['run']['steps'] ?? null) ? $kept['run'] : null;

        return ['values' => $values, 'run' => $run];
    }

    /** @param array<string, string> $values @param array<string, mixed>|null $run */
    private function keepWizard(Request $request, array $values, ?array $run): void
    {
        unset($values['_token'], $values['wizard-action'], $values['phase']);
        $request->session()->put(self::WIZARD, ['values' => $values, 'run' => $run]);
    }

    /** @param array<string, string> $errors what to say under which field */
    private function toStep(Request $request, string $step, array $errors = []): RedirectResponse
    {
        if ($errors !== [] && $request->hasSession()) {
            $request->session()->flash(self::WIZARD.'_errors', $errors);
        }

        return new RedirectResponse($step === 'kind' ? route('larena.env.admin.sites.new', [], false) : route('larena.env.admin.sites.new.step', ['step' => $step], false));
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
            // A way only a later panel can send (its archive) is not offered at all: nothing the person can do about it.
            if ($blocked === 'upload') {
                continue;
            }
            $choices[] = ['id' => $route->id, 'label' => $this->known('routes.names_of.'.$kind.'_'.$route->id, $this->known('routes.names.'.$route->id, $route->title)),
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
