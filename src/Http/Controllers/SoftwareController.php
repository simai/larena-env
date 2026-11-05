<?php

declare(strict_types=1);

namespace Larena\Env\Http\Controllers;

use Illuminate\Contracts\Translation\Translator;
use Illuminate\Contracts\View\Factory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Larena\Access\Runtime\AccessOperationAuthorizer;
use Larena\Env\Gateway\PanelActor;
use Larena\Env\Http\Middleware\ResolvePanelActor;
use Larena\Env\Http\Support\NoticeRedirect;
use Larena\Env\Operations\PanelOperations;
use Larena\Env\Server\ServerOverviewReader;
use Larena\Env\Server\SiteExtrasReader;
use Larena\Env\Software\SoftwareComponent;
use Larena\Env\Software\SoftwareScreen;

/**
 * «Программы и обновления» (simai-env 1.38.0, joined with the update by the panel's UX audit): the server's components with their versions, the updates waiting and their
 * services, a component added where the server can add it, and the system's package updates refreshed and installed,
 * each by plan and confirmation. The system level's alone. An update never reboots the server: the screen says when it
 * waits for one. PHP has its own screen.
 */
final readonly class SoftwareController
{
    /** What the screen offers, and the gateway command of each. */
    public const array OPERATIONS = [
        'refresh' => 'software:refresh', 'update' => 'software:update', 'install' => 'software:install',
        // One program updated alone, and one taken away (simai-env 1.43.0's catalogue).
        'upgrade' => 'software:install', 'remove' => 'software:remove',
    ];

    public function __construct(
        private PanelOperations $operations,
        private ServerOverviewReader $overviews,
        private SiteExtrasReader $extras,
        private Factory $views,
        private Translator $translator,
        private UpdateController $updates,
        private ?AccessOperationAuthorizer $authorizer = null,
    ) {
    }

    public function index(Request $request): mixed
    {
        $actor = ResolvePanelActor::of($request);
        $read = $this->overviews->read($actor);
        $capabilities = $read->overview?->capabilities;
        $software = $capabilities?->allows('software:list') === true ? $this->extras->serverSoftware($actor) : null;
        $canOperate = $this->authorizer?->authorize($request, 'env.server.operate')->isAllowed() ?? false;
        // PHP is a row of the table, read from the same kept answer as its own screen.
        $withPhp = $software !== null && $capabilities->allows('php:list') === true;
        $updates = $this->updates->state($request, $actor, $capabilities);

        return $this->views->make('larena-env::admin.software', [
            'source' => $read->source,
            'software' => $software,
            'system' => $software?->system,
            'reasonText' => $software === null ? $this->reasonText($read->reason ?? ($capabilities?->allows('software:list') === true ? 'unreadable' : 'unsupported')) : null,
            'allows' => array_map(static fn (string $command): bool => $canOperate && $capabilities?->allows($command) === true, self::OPERATIONS),
            'notice' => $request->session()->get('larena_env_notice'),
            'withPhp' => $withPhp,
            // The table's filter and search, from the address so a link keeps them.
            'filter' => in_array($request->query('show'), SoftwareScreen::FILTERS, true) ? (string) $request->query('show') : 'all',
            'query' => is_string($request->query('q')) ? mb_substr(trim($request->query('q')), 0, 64) : '',
            'phpRow' => $withPhp ? SoftwareScreen::php($this->extras->phpVersions($actor)) : null,
            'updateStates' => SoftwareScreen::updateStates($updates['plans']),
        ] + $updates);
    }

    /** What the asked change would do, with a confirmation that does it and nothing else. */
    public function plan(Request $request): mixed
    {
        $actor = ResolvePanelActor::of($request);
        [$op, $args] = $this->asked($request, $actor);
        if ($op === null) {
            return $this->back($request, 'not_allowed');
        }
        $result = $this->operations->planCommand($actor, self::OPERATIONS[$op], $args);
        if ($result->plan === null) {
            return $this->back($request, $result->reason ?? 'answer_shape_invalid');
        }

        return $this->views->make('larena-env::admin.plan', [
            'heading' => $this->heading($op, $args),
            'eyebrow' => (string) $this->translator->get('larena-env::admin.software.overview.title'),
            'target' => (string) $this->translator->get('larena-env::admin.software.overview.target'),
            'source' => $result->source,
            'plan' => $result->plan,
            'system' => $result->plan->system,
            'runUrl' => route('larena.env.admin.software.run', [], false),
            'cancelUrl' => route('larena.env.admin.software', [], false),
            'hidden' => ['op' => $op] + $args,
            'then' => $this->then($actor, $op, $args),
        ]);
    }

    /** The confirmation itself: the server makes the change as a job the next screen follows. */
    public function run(Request $request): RedirectResponse
    {
        $actor = ResolvePanelActor::of($request);
        [$op, $args] = $this->asked($request, $actor);
        if ($op === null) {
            return $this->back($request, 'not_allowed');
        }
        $started = $this->operations->startCommand($actor, self::OPERATIONS[$op], $args);
        if ($started->jobId === null) {
            return $this->back($request, $started->reason ?? 'answer_shape_invalid');
        }

        return new RedirectResponse(route('larena.env.admin.job', ['job' => $started->jobId], false));
    }

    /**
     * The operation and its arguments, when the server lists the command: a program added, updated alone or removed
     * only where the server says it would be, with a two-digit major version for Node.js or PostgreSQL. A form left
     * from an older page is refused.
     *
     * @return array{0: ?string, 1: array<string, string>}
     */
    private function asked(Request $request, PanelActor $actor): array
    {
        $op = $request->input('op');
        $capabilities = $this->overviews->read($actor)->overview?->capabilities;
        if (!is_string($op) || !isset(self::OPERATIONS[$op]) || $capabilities?->allows(self::OPERATIONS[$op]) !== true) {
            return [null, []];
        }
        if ($op === 'refresh') {
            return [$op, []];
        }
        if ($op === 'update') {
            return [$op, ['security-only' => $request->input('security_only') === 'yes' ? 'yes' : 'no']];
        }
        $name = $request->input('name');
        $component = is_string($name) ? $this->extras->serverSoftware($actor)?->component($name) : null;
        // Only what the server says it would do to that program.
        $offered = match ($op) {
            'install' => $component?->installable,
            'upgrade' => $component?->canUpdate,
            default => $component?->canRemove,
        };
        if ($component === null || $offered !== true) {
            return [null, []];
        }
        $args = ['name' => $component->name];
        $version = $request->input('version');
        if ($op === 'install' && in_array($component->name, SoftwareComponent::VERSIONED, true) && is_string($version) && $version !== '') {
            if (preg_match('/\A[0-9]{2}\z/D', $version) !== 1) {
                return [null, []];
            }
            $args['version'] = $version;
        }

        return [$op, $args];
    }

    /**
     * What the confirmation does, in words; a removal names what stays on the server.
     *
     * @param array<string, string> $args
     * @return list<string>
     */
    private function then(PanelActor $actor, string $op, array $args): array
    {
        $then = [(string) $this->translator->get('larena-env::admin.software.overview.then.'.$op)];
        $keeps = $op === 'remove' ? $this->extras->serverSoftware($actor)?->component($args['name'] ?? '')->keeps ?? [] : [];
        if ($keeps !== []) {
            $then[] = (string) $this->translator->get('larena-env::admin.software.overview.keeps', ['paths' => implode(', ', $keeps)]);
        }

        return $then;
    }

    /** @param array<string, string> $args */
    private function heading(string $op, array $args): string
    {
        $key = match ($op) {
            'update' => 'larena-env::admin.software.overview.operations.update_'.$args['security-only'],
            default => 'larena-env::admin.software.overview.operations.'.$op,
        };

        return (string) $this->translator->get($key, ['name' => $this->componentLabel($args['name'] ?? ''), 'version' => $args['version'] ?? '']);
    }

    private function componentLabel(string $name): string
    {
        $key = 'larena-env::admin.software.overview.components.'.$name;
        $line = (string) $this->translator->get($key);

        return $line === $key ? $name : $line;
    }

    private function back(Request $request, string $reason): RedirectResponse
    {
        return NoticeRedirect::to($request, route('larena.env.admin.software', [], false), $this->reasonText($reason));
    }

    private function reasonText(string $reason): string
    {
        foreach (['larena-env::admin.software.overview.reasons.'.$reason, 'larena-env::admin.reasons.'.$reason] as $key) {
            $line = (string) $this->translator->get($key);
            if ($line !== $key) {
                return $line;
            }
        }

        return (string) $this->translator->get('larena-env::admin.reasons.unknown', ['reason' => $reason]);
    }
}
