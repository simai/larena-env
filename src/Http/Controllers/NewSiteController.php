<?php

declare(strict_types=1);

namespace Larena\Env\Http\Controllers;

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
    private const array FIELDS = ['domain', 'profile', 'php', 'owner', 'create-db', 'db-engine', 'redirect-to', 'upstream-port', 'upstream-path', 'websockets'];
    /** The form's groups of fields and the site:add arguments each sends, asked only of a kind that takes them. */
    public const array FIELD_GROUPS = ['php' => ['php'], 'database' => ['create-db', 'db-engine'], 'redirect' => ['redirect-to'],
        'proxy' => ['upstream-port', 'upstream-path', 'websockets']];
    /** The session key that holds, by job, the new site whose daily backup is turned on once the site is made. */
    public const string AFTER_JOB = 'larena_env.after_job';

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

        return $this->views->make('larena-env::admin.new-site', [
            'profiles' => $profiles,
            'phpProfiles' => self::PHP_PROFILES,
            // The server's own kinds (Panel plan 5.5): what each is called and which fields it asks.
            'serverProfiles' => $server === null ? null : $this->described($server),
            'phpVersions' => $overview->phpVersions ?? [],
            // Only the whole server chooses whose the site is; a person's own site is theirs.
            'owners' => $actor->owner === null ? ($overview->owners ?? []) : null,
            'old' => self::input($request, $actor, $server),
            // What it runs is chosen first; the form then asks only what that kind takes.
            'kind' => in_array($request->query('profile'), $profiles, true) ? (string) $request->query('profile') : ($profiles[0] ?? 'static'),
            // A new site gets its daily backup by default (Panel plan 4.1), where the server's gateway can set one.
            'dailyBackup' => $this->schedules($overview) ? self::dailyBackup($request) : null,
            'notice' => $request->session()->get('larena_env_notice'),
        ]);
    }

    public function plan(Request $request): mixed
    {
        $actor = ResolvePanelActor::of($request);
        $overview = $this->overviews->read($actor)->overview;
        $input = self::withBackup(self::input($request, $actor, $this->serverProfiles($actor, $overview)), $request, $overview);
        $result = $this->operations->planCommand($actor, 'site:add', $input);
        if ($result->plan === null) {
            return $this->back($request, $result->reason ?? 'answer_shape_invalid', $input);
        }

        return $this->views->make('larena-env::admin.plan', [
            'heading' => (string) $this->translator->get('larena-env::admin.new_site.heading'),
            'eyebrow' => $input['domain'] ?? '',
            'target' => $input['domain'] ?? '',
            'source' => $result->source,
            'plan' => $result->plan,
            'runUrl' => route('larena.env.admin.sites.run', [], false),
            'cancelUrl' => route('larena.env.admin.sites.new', [], false),
            'hidden' => $input + ['daily-backup' => self::dailyBackup($request) ? 'yes' : 'no'],
            'then' => $this->followsWithSchedule($request, $overview, $input)
                ? [(string) $this->translator->get('larena-env::admin.new_site.daily_backup_after')] : [],
        ]);
    }

    public function run(Request $request): RedirectResponse
    {
        $actor = ResolvePanelActor::of($request);
        $overview = $this->overviews->read($actor)->overview;
        $input = self::withBackup(self::input($request, $actor, $this->serverProfiles($actor, $overview)), $request, $overview);
        $started = $this->operations->startCommand($actor, 'site:add', $input);
        if ($started->jobId === null) {
            return $this->back($request, $started->reason ?? 'answer_shape_invalid', $input);
        }

        // The site's daily backup is turned on when its job has made it (ServerJobController), as the plan said.
        if ($this->followsWithSchedule($request, $overview, $input) && isset($input['domain'])) {
            $request->session()->put(self::AFTER_JOB.'.'.$started->jobId, $input['domain']);
        }

        return new RedirectResponse(route('larena.env.admin.job', ['job' => $started->jobId], false));
    }

    /** On unless the form turned it off: the form sends `no` before the box, which sends `yes` when it is ticked. */
    private static function dailyBackup(Request $request): bool
    {
        return $request->input('daily-backup') !== 'no';
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
     *
     * @return array<string, string>
     */
    private static function withBackup(array $input, Request $request, ?\Larena\Env\Server\ServerOverview $overview): array
    {
        if ($overview?->capabilities?->takes('site:add', 'backup') === true && ($input['profile'] ?? null) !== 'redirect') {
            $input['backup'] = self::dailyBackup($request) ? 'yes' : 'no';
        }

        return $input;
    }

    /** @param array<string, string> $input */
    private function followsWithSchedule(Request $request, ?\Larena\Env\Server\ServerOverview $overview, array $input): bool
    {
        return self::dailyBackup($request) && !isset($input['backup']) && ($input['profile'] ?? null) !== 'redirect'
            && $overview?->capabilities?->allows('backup:data-schedule') === true;
    }

    /**
     * The gateway's arguments from the form, as typed; the command table refuses anything the gateway would. Each kind
     * sends only the fields it takes (PHP and a database for a PHP profile, the target for a redirect, the local port
     * for a proxy), the database engine only when one is asked for, and the owner only from the whole server.
     *
     * @param array<string, \Larena\Env\Server\SiteProfile>|null $server the server's own kinds, when it lists them
     *
     * @return array<string, string>
     */
    private static function input(Request $request, PanelActor $actor, ?array $server = null): array
    {
        $input = [];
        foreach (self::FIELDS as $field) {
            $value = $request->input($field);
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
     * The groups of fields a kind asks: from the server's own description of it where the server lists its kinds,
     * else what the panel knows of the six every server has.
     *
     * @param array<string, \Larena\Env\Server\SiteProfile>|null $server
     *
     * @return list<string>
     */
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
            $named[$profile->name] = $profile;
        }

        return $named;
    }

    /**
     * What the wizard says of each of the server's kinds: the panel's words for one it knows, else its name and the
     * server's own line; and which fields it asks.
     *
     * @param array<string, \Larena\Env\Server\SiteProfile> $server
     *
     * @return array<string, array{label: string, hint: string, groups: list<string>}>
     */
    private function described(array $server): array
    {
        $described = [];
        foreach ($server as $name => $profile) {
            $known = in_array($name, self::KNOWN_PROFILES, true);
            $described[$name] = [
                'label' => $known ? (string) $this->translator->get('larena-env::admin.new_site.profiles.'.$name) : $name,
                'hint' => $known ? (string) $this->translator->get('larena-env::admin.new_site.profile_hints.'.$name) : ($profile->description ?? ''),
                'groups' => self::groupsOf($name, $server),
            ];
        }

        return $described;
    }

    /** @param array<string, string> $input */
    private function back(Request $request, string $reason, array $input): RedirectResponse
    {
        return NoticeRedirect::to($request, route('larena.env.admin.sites.new', [], false).'?'.http_build_query($input), $this->reasonText($reason));
    }

    private function reasonText(string $reason): string
    {
        $key = 'larena-env::admin.reasons.'.$reason;
        $line = (string) $this->translator->get($key);

        return $line !== $key ? $line : (string) $this->translator->get('larena-env::admin.reasons.unknown', ['reason' => $reason]);
    }
}
