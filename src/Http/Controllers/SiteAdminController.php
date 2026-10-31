<?php

declare(strict_types=1);

namespace Larena\Env\Http\Controllers;

use Illuminate\Contracts\Translation\Translator;
use Illuminate\Contracts\View\Factory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Larena\Env\Http\Middleware\ResolvePanelActor;
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
    private const array LOG_KINDS = ['access', 'error'];
    private const int LOG_LINES = 200;

    public function __construct(
        private SiteDetailsReader $sites,
        private PanelOperations $operations,
        private Factory $views,
        private Translator $translator,
    ) {
    }

    public function show(Request $request, string $domain): mixed
    {
        [$user, $owner] = ResolvePanelActor::of($request);
        $result = $this->sites->read($user, $owner, $domain);
        $kind = in_array($request->query('log'), self::LOG_KINDS, true) ? (string) $request->query('log') : 'access';
        $log = $result->site === null ? null : $this->sites->log($user, $owner, $domain, $kind, self::LOG_LINES);

        return $this->views->make('larena-env::admin.site', [
            'domain' => $domain,
            'source' => $result->source,
            'site' => $result->site,
            'refused' => $result->refused,
            'reasonText' => $result->reason === null ? null : $this->reasonText($result->reason),
            'operations' => $result->site === null ? [] : SiteOperation::offeredFor($result->site),
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
        [$user, $owner] = ResolvePanelActor::of($request);
        $operation = SiteOperation::tryFrom((string) $request->input('operation'));
        if ($operation === null) {
            return $this->back($domain, 'not_allowed');
        }
        $input = self::input($request, $operation);
        $result = $this->operations->plan($user, $owner, $operation, $domain, $input);
        if ($result->plan === null) {
            return $this->back($domain, $result->reason ?? 'answer_shape_invalid');
        }

        return $this->views->make('larena-env::admin.plan', [
            'domain' => $domain,
            'source' => $result->source,
            'operation' => $operation,
            'input' => $input,
            'plan' => $result->plan,
        ]);
    }

    /** The confirmation itself: the gateway starts the action and the screen follows the job it answers. */
    public function run(Request $request, string $domain): RedirectResponse
    {
        [$user, $owner] = ResolvePanelActor::of($request);
        $operation = SiteOperation::tryFrom((string) $request->input('operation'));
        if ($operation === null) {
            return $this->back($domain, 'not_allowed');
        }
        $started = $this->operations->start($user, $owner, $operation, $domain, self::input($request, $operation));
        if ($started->jobId === null) {
            return $this->back($domain, $started->reason ?? 'answer_shape_invalid');
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


    private function back(string $domain, string $reason): RedirectResponse
    {
        return (new RedirectResponse(route('larena.env.admin.site', ['domain' => $domain], false)))
            ->with('larena_env_notice', $this->reasonText($reason));
    }

    private function reasonText(string $reason): string
    {
        $key = 'larena-env::admin.reasons.'.$reason;
        $line = (string) $this->translator->get($key);

        return $line !== $key ? $line : (string) $this->translator->get('larena-env::admin.reasons.unknown', ['reason' => $reason]);
    }
}
