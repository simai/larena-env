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
    /** The kinds simai-env creates (site:add profiles); a plain reverse proxy waits for simai-env to have one. */
    public const array PROFILES = ['static', 'generic', 'laravel', 'wordpress', 'node', 'redirect'];
    private const array PHP_PROFILES = ['generic', 'laravel', 'wordpress'];
    private const array FIELDS = ['domain', 'profile', 'php', 'owner', 'create-db', 'db-engine', 'redirect-to'];
    /** The session key that holds, by job, the new site whose daily backup is turned on once the site is made. */
    public const string AFTER_JOB = 'larena_env.after_job';

    public function __construct(
        private ServerOverviewReader $overviews,
        private PanelOperations $operations,
        private Factory $views,
        private Translator $translator,
    ) {
    }

    public function create(Request $request): mixed
    {
        $actor = ResolvePanelActor::of($request);
        $overview = $this->overviews->read($actor)->overview;

        return $this->views->make('larena-env::admin.new-site', [
            'profiles' => self::PROFILES,
            'phpProfiles' => self::PHP_PROFILES,
            'phpVersions' => $overview->phpVersions ?? [],
            // Only the whole server chooses whose the site is; a person's own site is theirs.
            'owners' => $actor->owner === null ? ($overview->owners ?? []) : null,
            'old' => self::input($request, $actor),
            // What it runs is chosen first; the form then asks only what that kind takes.
            'kind' => in_array($request->query('profile'), self::PROFILES, true) ? (string) $request->query('profile') : 'static',
            // A new site gets its daily backup by default (Panel plan 4.1), where the server's gateway can set one.
            'dailyBackup' => $this->schedules($overview) ? self::dailyBackup($request) : null,
            'notice' => $request->session()->get('larena_env_notice'),
        ]);
    }

    public function plan(Request $request): mixed
    {
        $actor = ResolvePanelActor::of($request);
        $input = self::input($request, $actor);
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
            'then' => self::dailyBackup($request) && $this->schedules($this->overviews->read($actor)->overview)
                ? [(string) $this->translator->get('larena-env::admin.new_site.daily_backup_after')] : [],
        ]);
    }

    public function run(Request $request): RedirectResponse
    {
        $actor = ResolvePanelActor::of($request);
        $input = self::input($request, $actor);
        $started = $this->operations->startCommand($actor, 'site:add', $input);
        if ($started->jobId === null) {
            return $this->back($request, $started->reason ?? 'answer_shape_invalid', $input);
        }

        // The site's daily backup is turned on when its job has made it (ServerJobController), as the plan said.
        if (self::dailyBackup($request) && $this->schedules($this->overviews->read($actor)->overview) && isset($input['domain'])) {
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
        return $overview?->capabilities?->allows('backup:data-schedule') === true;
    }

    /**
     * The gateway's arguments from the form, as typed; the command table refuses anything the gateway would. PHP and a
     * database go only with a PHP profile, the database only when asked, the redirect target only with a redirect, and the owner
     * only from the whole server.
     *
     * @return array<string, string>
     */
    private static function input(Request $request, PanelActor $actor): array
    {
        $input = [];
        foreach (self::FIELDS as $field) {
            $value = $request->input($field);
            if (is_string($value) && trim($value) !== '') {
                $input[$field] = trim($value);
            }
        }
        $profile = $input['profile'] ?? null;
        if (!in_array($profile, self::PHP_PROFILES, true)) {
            unset($input['php']);
        }
        if (!in_array($profile, self::PHP_PROFILES, true)) {
            unset($input['create-db']);
        }
        if (($input['create-db'] ?? null) !== 'yes') {
            unset($input['db-engine']);
        }
        if ($profile !== 'redirect') {
            unset($input['redirect-to']);
        }
        if ($actor->owner !== null) {
            unset($input['owner']);
        }

        return $input;
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
