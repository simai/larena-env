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

/**
 * A started action: where it stands and what it has printed. The screen reads again when it is opened again, so a
 * running job is followed by looking, never by the panel doing anything else on the server.
 */
final readonly class ServerJobController
{
    public function __construct(
        private PanelOperations $operations,
        private Factory $views,
        private Translator $translator,
    ) {
    }

    public function show(Request $request, string $job): mixed
    {
        $actor = ResolvePanelActor::of($request);
        $after = (int) $request->query('after', '0');
        $result = $this->operations->view($actor, $job, max(0, $after));

        return $this->views->make('larena-env::admin.job', [
            'jobId' => $job,
            'source' => $result->source,
            'status' => $result->status,
            'log' => $result->log,
            'refused' => $result->refused,
            'reasonText' => $result->reason === null ? null : $this->reasonText($result->reason),
            'notice' => $request->session()->get('larena_env_notice'),
        ]);
    }

    public function cancel(Request $request, string $job): RedirectResponse
    {
        $actor = ResolvePanelActor::of($request);
        $result = $this->operations->cancel($actor, $job);
        $address = route('larena.env.admin.job', ['job' => $job], false);

        return $result->reason === null
            ? new RedirectResponse($address)
            : NoticeRedirect::to($request, $address, $this->reasonText($result->reason));
    }


    private function reasonText(string $reason): string
    {
        $key = 'larena-env::admin.reasons.'.$reason;
        $line = (string) $this->translator->get($key);

        return $line !== $key ? $line : (string) $this->translator->get('larena-env::admin.reasons.unknown', ['reason' => $reason]);
    }
}
