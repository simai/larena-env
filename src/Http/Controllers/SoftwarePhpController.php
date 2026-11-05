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
use Larena\Env\Software\PhpVersions;

/**
 * «Программы › PHP» (simai-env 1.37.0): the server's PHP versions, the sites on each, the fixes waiting, and a version
 * installed next to the others, updated within itself, removed once free, its extensions turned on or off, each by plan
 * and confirmation. The system level's alone. A new version touches no site: the sites move one by one on their own
 * screens (site:set-php), and the old version is removed once none uses it. The server refuses a removal while a site,
 * the system or the `php` command uses the version, so «Удалить» is offered only where it says the version is free.
 */
final readonly class SoftwarePhpController
{
    /** What the screen offers, and the gateway command of each. */
    public const array OPERATIONS = ['install' => 'php:install', 'update' => 'php:update', 'remove' => 'php:remove', 'extension' => 'php:extension'];

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
        $php = $capabilities?->allows('php:list') === true ? $this->extras->phpVersions($actor) : null;
        $canOperate = $this->authorizer?->authorize($request, 'env.server.operate')->isAllowed() ?? false;
        // The extensions of one version, opened from its row.
        $extensionsOf = $request->query('extensions');
        $extensionsOf = is_string($extensionsOf) && $php?->has($extensionsOf) !== null ? $extensionsOf : null;
        $allows = array_map(static fn (string $command): bool => $canOperate && $capabilities?->allows($command) === true, self::OPERATIONS);

        return $this->views->make('larena-env::admin.software-php', [
            'source' => $read->source,
            'php' => $php,
            'reasonText' => $php === null ? $this->reasonText($read->reason ?? ($capabilities?->allows('php:list') === true ? 'unreadable' : 'unsupported')) : null,
            'allows' => $allows,
            'extensionsOf' => $extensionsOf,
            'extensions' => $extensionsOf !== null && $capabilities->allows('php:extensions') === true ? $this->extras->phpExtensions($actor, $extensionsOf) : null,
            'notice' => $request->session()->get('larena_env_notice'),
        ]);
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
            'eyebrow' => (string) $this->translator->get('larena-env::admin.software.php.title'),
            'target' => 'PHP '.$args['php'],
            'source' => $result->source,
            'plan' => $result->plan,
            'runUrl' => route('larena.env.admin.software.php.run', [], false),
            'cancelUrl' => route('larena.env.admin.software.php', $op === 'extension' ? ['extensions' => $args['php']] : [], false),
            'hidden' => ['op' => $op] + $args,
            'then' => [(string) $this->translator->get('larena-env::admin.software.php.then.'.$op)],
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
     * The operation and its arguments, when the server lists the command and the version fits it: one it could install,
     * one it has, one it says is free to remove. A form left from an older page is refused in words.
     *
     * @return array{0: ?string, 1: array<string, string>}
     */
    private function asked(Request $request, PanelActor $actor): array
    {
        $op = $request->input('op');
        $version = $request->input('php');
        $capabilities = $this->overviews->read($actor)->overview?->capabilities;
        if (!is_string($op) || !isset(self::OPERATIONS[$op]) || !is_string($version) || $capabilities?->allows(self::OPERATIONS[$op]) !== true) {
            return [null, []];
        }
        $php = $this->extras->phpVersions($actor);
        $have = $php?->has($version);
        $args = ['php' => $version];
        $fits = match ($op) {
            'install' => $php instanceof PhpVersions && in_array($version, $php->installable, true),
            'update' => $have !== null,
            'remove' => $have?->removable === true,
            'extension' => $have !== null && $this->extension($request, $args),
        };
        if ($op === 'install') {
            $args['include-common'] = $request->boolean('include_common', true) ? 'yes' : 'no';
        }

        return $fits ? [$op, $args] : [null, []];
    }

    /**
     * The extension and whether it is turned on, written into the arguments when the name is one php:extension takes.
     *
     * @param array<string, string> $args
     */
    private function extension(Request $request, array &$args): bool
    {
        $name = $request->input('name');
        $enabled = $request->input('enabled');
        if (!is_string($name) || preg_match('/\A[a-z0-9_]{2,32}\z/D', $name) !== 1 || !in_array($enabled, ['yes', 'no'], true)) {
            return false;
        }
        $args['name'] = $name;
        $args['enabled'] = $enabled;

        return true;
    }

    /** @param array<string, string> $args */
    private function heading(string $op, array $args): string
    {
        $key = $op === 'extension' ? 'larena-env::admin.software.php.operations.extension_'.$args['enabled'] : 'larena-env::admin.software.php.operations.'.$op;

        return (string) $this->translator->get($key, ['php' => $args['php'], 'name' => $args['name'] ?? '']);
    }

    private function back(Request $request, string $reason): RedirectResponse
    {
        return NoticeRedirect::to($request, route('larena.env.admin.software.php', [], false), $this->reasonText($reason));
    }

    private function reasonText(string $reason): string
    {
        foreach (['larena-env::admin.software.php.reasons.'.$reason, 'larena-env::admin.reasons.'.$reason] as $key) {
            $line = (string) $this->translator->get($key);
            if ($line !== $key) {
                return $line;
            }
        }

        return (string) $this->translator->get('larena-env::admin.reasons.unknown', ['reason' => $reason]);
    }
}
