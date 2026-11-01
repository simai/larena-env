<?php

declare(strict_types=1);

namespace Larena\Env\Http\Controllers;

use Illuminate\Contracts\Translation\Translator;
use Illuminate\Contracts\View\Factory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Larena\Access\Runtime\AccessOperationAuthorizer;
use Larena\Env\Http\Middleware\ResolvePanelActor;
use Larena\Env\Http\Support\NoticeRedirect;
use Larena\Env\Operations\PanelOperations;
use Larena\Env\Server\ServerOverviewReader;
use Larena\Env\Server\ServiceState;
use Larena\Env\Server\SiteExtrasReader;

/**
 * «Сервер › Службы» (Panel plan 5.1): each service of the server and whether it runs, a restart or a reload of its
 * settings by plan and confirmation, and the last lines of its journal. The system level's alone. It is what an
 * emergency needs from a phone, so the screen is a list of short rows with large buttons. The server checks a
 * service's settings before it restarts or reloads it (nginx -t, php-fpm -t), and ends a failed job with the journal.
 */
final readonly class ServicesController
{
    /** What a service's row offers, and the gateway command of each. */
    public const array OPERATIONS = ['restart' => 'service:restart', 'reload' => 'service:reload'];
    /** How many of the journal's last lines are read. */
    private const int LOG_LINES = 100;

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
        $actor = ResolvePanelActor::of($request);
        $read = $this->overviews->read($actor);
        $capabilities = $read->overview?->capabilities;
        $services = $read->overview->services ?? [];
        $names = array_map(static fn (ServiceState $service): string => $service->name, $services);
        $logOf = $request->query('log');
        $log = is_string($logOf) && in_array($logOf, $names, true) && $capabilities?->allows('service:log') === true
            ? $this->extras->serviceLog($actor, $logOf, self::LOG_LINES) : null;
        $canOperate = $this->authorizer?->authorize($request, 'env.server.operate')->isAllowed() ?? false;

        return $this->views->make('larena-env::admin.services', [
            'source' => $read->source,
            'services' => $services,
            'reasonText' => $read->reason === null ? null : $this->reasonText($read->reason),
            // Each action only where the server lists it and the person may change the server.
            'allows' => [
                'restart' => $canOperate && $capabilities?->allows('service:restart') === true,
                'reload' => $canOperate && $capabilities?->allows('service:reload') === true,
                'log' => $capabilities?->allows('service:log') === true,
            ],
            'logOf' => is_string($logOf) && in_array($logOf, $names, true) ? $logOf : null,
            'log' => $log,
            'notice' => $request->session()->get('larena_env_notice'),
        ]);
    }

    /** Whether a service rereads its settings without a restart: nginx and PHP-FPM, each after the server checks them. */
    public static function reloads(string $name): bool
    {
        return $name === 'nginx' || preg_match('/\Aphp[5-9]\.[0-9]-fpm\z/D', $name) === 1;
    }

    /** What the restart or the reload would do, with a confirmation that does it and nothing else. */
    public function plan(Request $request): mixed
    {
        $actor = ResolvePanelActor::of($request);
        [$op, $name] = $this->asked($request, $actor);
        if ($op === null || $name === null) {
            return $this->back($request, 'not_allowed');
        }
        $result = $this->operations->planCommand($actor, self::OPERATIONS[$op], ['name' => $name]);
        if ($result->plan === null) {
            return $this->back($request, $result->reason ?? 'answer_shape_invalid');
        }

        return $this->views->make('larena-env::admin.plan', [
            'heading' => (string) $this->translator->get('larena-env::admin.services.operations.'.$op, ['name' => $name]),
            'eyebrow' => (string) $this->translator->get('larena-env::admin.services.title'),
            'target' => $name,
            'source' => $result->source,
            'plan' => $result->plan,
            'runUrl' => route('larena.env.admin.services.run', [], false),
            'cancelUrl' => route('larena.env.admin.services', [], false),
            'hidden' => ['op' => $op, 'name' => $name],
            'then' => [(string) $this->translator->get('larena-env::admin.services.checked_first')],
        ]);
    }

    /** The confirmation itself: the server restarts or reloads the service as a job the next screen follows. */
    public function run(Request $request): RedirectResponse
    {
        $actor = ResolvePanelActor::of($request);
        [$op, $name] = $this->asked($request, $actor);
        if ($op === null || $name === null) {
            return $this->back($request, 'not_allowed');
        }
        $started = $this->operations->startCommand($actor, self::OPERATIONS[$op], ['name' => $name]);
        if ($started->jobId === null) {
            return $this->back($request, $started->reason ?? 'answer_shape_invalid');
        }

        return new RedirectResponse(route('larena.env.admin.job', ['job' => $started->jobId], false));
    }

    /**
     * The operation and the service asked for, when the server lists both: a service it does not report, or a form
     * left from an older page, is refused in words.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function asked(Request $request, \Larena\Env\Gateway\PanelActor $actor): array
    {
        $op = $request->input('op');
        $name = $request->input('name');
        $overview = $this->overviews->read($actor)->overview;
        if (!is_string($op) || !isset(self::OPERATIONS[$op]) || $overview?->capabilities?->allows(self::OPERATIONS[$op]) !== true) {
            return [null, null];
        }
        foreach ($overview->services as $service) {
            if ($service->name === $name && ($op !== 'reload' || self::reloads($service->name))) {
                return [$op, $service->name];
            }
        }

        return [$op, null];
    }

    private function back(Request $request, string $reason): RedirectResponse
    {
        return NoticeRedirect::to($request, route('larena.env.admin.services', [], false), $this->reasonText($reason));
    }

    private function reasonText(string $reason): string
    {
        $key = 'larena-env::admin.reasons.'.$reason;
        $line = (string) $this->translator->get($key);

        return $line !== $key ? $line : (string) $this->translator->get('larena-env::admin.reasons.unknown', ['reason' => $reason]);
    }
}
