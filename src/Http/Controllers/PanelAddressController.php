<?php

declare(strict_types=1);

namespace Larena\Env\Http\Controllers;

use Illuminate\Contracts\Translation\Translator;
use Illuminate\Contracts\View\Factory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Larena\Access\Runtime\AccessOperationAuthorizer;
use Larena\Env\Gateway\PanelCapabilities;
use Larena\Env\Gateway\PanelCommands;
use Larena\Env\Gateway\PanelRefused;
use Larena\Env\Http\Middleware\ResolvePanelActor;
use Larena\Env\Http\Support\NoticeRedirect;
use Larena\Env\Operations\PanelOperations;
use Larena\Env\Server\ServerOverviewReader;
use Larena\Env\Server\SiteExtrasReader;

/**
 * «Адрес панели» on «Доступ к панели» (simai-env 1.51.0), the administrator's: a domain attached with Let's Encrypt or
 * taken away, the port moved, the address by IP turned off once the domain has its certificate. The port and the
 * address by IP are timed changes the server undoes in 5 minutes unless kept from the new address, so their
 * confirmation does not wait for the job here: it sends the person to the job's page on the address that will answer,
 * where «Оставить» keeps it.
 */
final readonly class PanelAddressController
{
    public const array OPERATIONS = ['domain' => 'panel:domain', 'domain-remove' => 'panel:domain', 'port' => 'panel:port', 'ip-access' => 'panel:ip-access'];

    public function __construct(
        private PanelOperations $operations,
        private ServerOverviewReader $overviews,
        private SiteExtrasReader $extras,
        private Factory $views,
        private Translator $translator,
        private ?AccessOperationAuthorizer $authorizer = null,
    ) {
    }

    public function plan(Request $request): mixed
    {
        $actor = ResolvePanelActor::of($request);
        $asked = $this->asked($request);
        if (is_string($asked)) {
            return $this->back($request, $asked);
        }
        [$op, $command, $args] = $asked;
        $result = $this->operations->planCommand($actor, $command, $args);
        if ($result->plan === null) {
            return $this->back($request, $result->reason ?? 'answer_shape_invalid');
        }
        $then = in_array($op, ['port', 'ip-access'], true) ? [$this->text('panel_address.timed_then')] : [];

        return $this->views->make('larena-env::admin.plan', [
            'heading' => $this->text('panel_address.operations.'.$op),
            'eyebrow' => $this->text('panel_address.title'),
            'target' => $this->text('panel_address.title'),
            'source' => $result->source,
            'plan' => $result->plan,
            'runUrl' => route('larena.env.admin.panel.address.run', [], false),
            'cancelUrl' => route('larena.env.admin.panel.access', [], false),
            'hidden' => ['op' => $op] + $args,
            'then' => $then,
        ]);
    }

    public function run(Request $request): mixed
    {
        $actor = ResolvePanelActor::of($request);
        $asked = $this->asked($request);
        if (is_string($asked)) {
            return $this->back($request, $asked);
        }
        [$op, $command, $args] = $asked;
        $address = in_array($op, ['port', 'ip-access'], true) ? $this->extras->panelAddress($actor) : null;
        $started = $this->operations->startCommand($actor, $command, $args);
        if ($started->jobId === null) {
            return $this->back($request, $started->reason ?? 'answer_shape_invalid');
        }
        $job = route('larena.env.admin.job', ['job' => $started->jobId], false);
        // Where the panel answers once the change is made: the job's page there keeps it.
        $next = match ($op) {
            'port' => $address?->ipUrlOnPort((int) $args['port']),
            'ip-access' => $args['enabled'] === 'no' ? $address?->domainUrl : $address?->ipUrl,
            default => null,
        };
        if ($next === null) {
            return new RedirectResponse($job);
        }

        return $this->views->make('larena-env::admin.panel-address-moving', [
            'op' => $op,
            'next' => $next.$job,
            'nextBase' => $next,
            'port' => $args['port'] ?? null,
        ]);
    }

    /**
     * The asked change and its arguments, or why it is not done.
     *
     * @return array{0: string, 1: string, 2: array<string, string>}|string
     */
    private function asked(Request $request): array|string
    {
        if (!($this->authorizer?->authorize($request, 'env.server.operate')->isAllowed() ?? false)) {
            return 'not_allowed';
        }
        $op = (string) $request->input('op');
        $command = self::OPERATIONS[$op] ?? null;
        $capabilities = $this->overviews->read(ResolvePanelActor::of($request))->overview->capabilities ?? PanelCapabilities::none();
        if ($command === null || !$capabilities->allows($command)) {
            return 'not_allowed';
        }
        $args = match ($op) {
            'domain' => array_filter(['domain' => strtolower(trim((string) $request->input('domain'))), 'email' => trim((string) $request->input('email'))],
                static fn (string $value): bool => $value !== ''),
            'domain-remove' => ['remove' => 'yes'],
            'port' => ['port' => trim((string) $request->input('port'))],
            default => ['enabled' => $request->input('enabled') === 'yes' ? 'yes' : 'no'],
        };
        if ($op === 'domain' && !isset($args['domain'])) {
            return 'panel_domain_needed';
        }
        try {
            PanelCommands::check($command, $args, true);
        } catch (PanelRefused $refused) {
            return $refused->reasonCode;
        }

        return [$op, $command, $args];
    }

    private function back(Request $request, string $reason): RedirectResponse
    {
        $key = 'larena-env::admin.reasons.'.$reason;
        $line = (string) $this->translator->get($key);

        return NoticeRedirect::to($request, route('larena.env.admin.panel.access', [], false),
            $line !== $key ? $line : (string) $this->translator->get('larena-env::admin.reasons.unknown', ['reason' => $reason]));
    }

    private function text(string $key): string
    {
        return (string) $this->translator->get('larena-env::admin.'.$key);
    }
}
