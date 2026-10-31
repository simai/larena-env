<?php

declare(strict_types=1);

namespace Larena\Env\Http\Controllers;

use Illuminate\Contracts\Translation\Translator;
use Illuminate\Contracts\View\Factory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Larena\Env\Http\Middleware\ResolvePanelActor;
use Larena\Env\Http\Support\NoticeRedirect;
use Larena\Env\Operations\PanelOperations;
use Larena\Env\Operations\SiteOperation;
use Larena\Env\Server\SiteDetailsReader;

/**
 * One site: what it runs on, its certificate, its backups and the last lines of its log, and the actions that belong
 * to them. An action is never done from this screen: it is planned, shown in words, and only a confirmation of that
 * plan starts it as a job the next screen follows.
 */
final readonly class SiteAdminController
{
    /** The card's tabs (Panel plan 2.3): its state and problems, domains and certificate, PHP, releases, log, access. */
    private const array TABS = ['overview', 'domains', 'php', 'releases', 'log', 'access'];
    private const array LOG_KINDS = ['access', 'error'];
    private const int LOG_LINES = 200;

    public function __construct(
        private SiteDetailsReader $sites,
        private \Larena\Env\Server\ServerOverviewReader $overviews,
        private PanelOperations $operations,
        private Factory $views,
        private Translator $translator,
    ) {
    }

    public function show(Request $request, string $domain): mixed
    {
        $actor = ResolvePanelActor::of($request);
        $result = $this->sites->read($actor, $domain);
        $tab = in_array($request->query('tab'), self::TABS, true) ? (string) $request->query('tab') : self::TABS[0];
        $kind = in_array($request->query('log'), self::LOG_KINDS, true) ? (string) $request->query('log') : 'access';
        // Each tab asks the gateway only for what it shows.
        $log = $result->site === null || $tab !== 'log' ? null : $this->sites->log($actor, $domain, $kind, self::LOG_LINES);
        $doctor = $result->site === null || $tab !== 'overview' ? null : $this->sites->doctor($actor, $domain);
        $operations = $result->site === null ? [] : SiteOperation::offeredFor($result->site);

        return $this->views->make('larena-env::admin.site', [
            'domain' => $domain,
            'tab' => $tab,
            'tabs' => self::TABS,
            'doctor' => $doctor,
            'phpVersions' => $tab === 'php' && $result->site !== null ? $this->phpVersions($actor) : [],
            'tabOperations' => array_values(array_filter($operations, static fn (SiteOperation $operation): bool => $operation->tab() === $tab)),
            'source' => $result->source,
            'site' => $result->site,
            'refused' => $result->refused,
            'reasonText' => $result->reason === null ? null : $this->reasonText($result->reason),
            'logKind' => $kind,
            'logKinds' => self::LOG_KINDS,
            'log' => $log?->log,
            'logReasonText' => $log === null || $log->reason === null ? null : $this->reasonText($log->reason),
            'notice' => $request->session()->get('larena_env_notice'),
        ]);
    }

    /** What the action would do, with a confirmation that starts it and nothing else. */
    public function plan(Request $request, string $domain): mixed
    {
        $actor = ResolvePanelActor::of($request);
        $operation = SiteOperation::tryFrom((string) $request->input('operation'));
        if ($operation === null) {
            return $this->back($request, $domain, 'not_allowed');
        }
        $input = self::input($request, $operation);
        $result = $this->operations->plan($actor, $operation, $domain, $input);
        if ($result->plan === null) {
            return $this->back($request, $domain, $result->reason ?? 'answer_shape_invalid', $operation->tab());
        }

        return $this->views->make('larena-env::admin.plan', [
            'heading' => (string) $this->translator->get('larena-env::admin.operations.'.$operation->value),
            'eyebrow' => $domain,
            'target' => $domain,
            'source' => $result->source,
            'plan' => $result->plan,
            'runUrl' => route('larena.env.admin.site.run', ['domain' => $domain], false),
            'cancelUrl' => route('larena.env.admin.site', ['domain' => $domain], false).'?tab='.$operation->tab(),
            'hidden' => ['operation' => $operation->value] + $input,
        ]);
    }

    /** The confirmation itself: the gateway starts the action and the screen follows the job it answers. */
    public function run(Request $request, string $domain): RedirectResponse
    {
        $actor = ResolvePanelActor::of($request);
        $operation = SiteOperation::tryFrom((string) $request->input('operation'));
        if ($operation === null) {
            return $this->back($request, $domain, 'not_allowed');
        }
        $started = $this->operations->start($actor, $operation, $domain, self::input($request, $operation));
        if ($started->jobId === null) {
            return $this->back($request, $domain, $started->reason ?? 'answer_shape_invalid', $operation->tab());
        }

        return new RedirectResponse(route('larena.env.admin.job', ['job' => $started->jobId], false));
    }

    /**
     * The action's own fields as the form sent them, left as typed: the command table refuses what the gateway would.
     *
     * @return array<string, string>
     */
    private static function input(Request $request, SiteOperation $operation): array
    {
        $input = [];
        foreach ($operation->fields() as $field) {
            $value = $request->input($field);
            if (is_string($value) && $value !== '') {
                $input[$field] = $value;
            }
        }

        return $input;
    }


    private function back(Request $request, string $domain, string $reason, string $tab = 'overview'): RedirectResponse
    {
        return NoticeRedirect::to($request, route('larena.env.admin.site', ['domain' => $domain], false).'?tab='.$tab, $this->reasonText($reason));
    }

    /** @return list<string> the server's PHP versions, which the PHP tab offers */
    private function phpVersions(\Larena\Env\Gateway\PanelActor $actor): array
    {
        return $this->overviews->read($actor)->overview->phpVersions ?? [];
    }

    private function reasonText(string $reason): string
    {
        $key = 'larena-env::admin.reasons.'.$reason;
        $line = (string) $this->translator->get($key);

        return $line !== $key ? $line : (string) $this->translator->get('larena-env::admin.reasons.unknown', ['reason' => $reason]);
    }
}
