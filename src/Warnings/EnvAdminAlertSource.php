<?php

declare(strict_types=1);

namespace Larena\Env\Warnings;

use Closure;
use DateTimeImmutable;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Http\Request;
use Larena\Access\Runtime\AccessOperationAuthorizer;
use Larena\Admin\Alerts\AdminAlert;
use Larena\Admin\Alerts\AdminAlertAction;
use Larena\Admin\Alerts\AdminAlertLevel;
use Larena\Admin\Contracts\AdminAlertSource;
use Larena\Env\Gateway\PanelActor;
use Larena\Env\Gateway\PanelRefused;
use Larena\Env\Http\Middleware\ResolvePanelActor;
use Larena\Env\Http\Support\PanelActorResolver;
use Larena\Env\Server\ServerOverviewReader;
use Larena\Env\Server\SiteExtrasReader;

/**
 * The panel's warnings in the admin's alert bar (owner decision 2026-11-05), on every admin page: the server's own
 * alerts where it keeps them (simai-env 1.31.0), else what the panel works out, for this person's level — a site's
 * owner sees their sites only. An alert someone acknowledged is seen and stays out of the bar. The server is asked at
 * most once a minute per person: `$remember` keeps the warnings (the words are made per request, in its language).
 */
final readonly class EnvAdminAlertSource implements AdminAlertSource
{
    /** How long the server's answer is reused, in seconds. */
    public const int TTL = 60;

    /**
     * @param Closure(string, int, Closure(): list<PanelWarning>): list<PanelWarning>|null $remember a cache's remember()
     * @param list<string> $ownHosts the panel's own addresses: its site is not one of the server's sites to warn about
     */
    public function __construct(
        private PanelActorResolver $actors,
        private ServerOverviewReader $overviews,
        private SiteExtrasReader $extras,
        private Translator $translator,
        private ?AccessOperationAuthorizer $authorizer = null,
        private ?Closure $remember = null,
        private array $ownHosts = [],
    ) {
    }

    public function alerts(Request $request): array
    {
        $actor = $request->attributes->get(ResolvePanelActor::ATTRIBUTE);
        if (!$actor instanceof PanelActor) {
            try {
                $actor = $this->actors->resolve($request);
            } catch (PanelRefused) {
                // Not a person of the panel: nothing of the server is theirs to see.
                return [];
            }
        }
        $read = fn (): array => $this->warnings($actor, $request);
        $warnings = $this->remember === null ? $read()
            : ($this->remember)('larena-env:alerts:'.sha1($actor->user."\0".($actor->owner ?? '')), self::TTL, $read);
        $canAcknowledge = $this->authorizer?->authorize($request, 'env.sites.operate')->isAllowed() ?? false;
        $back = $request->getRequestUri();
        $alerts = [];
        foreach ($warnings as $warning) {
            if ($warning->state === 'acked' || $warning->state === 'resolved') {
                continue;
            }
            $alerts[] = $this->alert($warning, $canAcknowledge, $back);
        }

        return $alerts;
    }

    /** @return list<PanelWarning> */
    private function warnings(PanelActor $actor, Request $request): array
    {
        $overview = $this->overviews->read($actor)->overview?->withoutSites(array_values(array_unique([...$this->ownHosts, $request->getHost()])));
        if ($overview === null) {
            return [];
        }
        $metrics = $actor->owner === null && $overview->capabilities?->allows('self:metrics') === true ? $this->extras->metrics($actor) : null;

        return (new PanelWarningSource($this->extras))->of($actor, $overview, $metrics, PanelWarnings::now())['warnings'];
    }

    private function alert(PanelWarning $warning, bool $canAcknowledge, string $back): AdminAlert
    {
        $text = fn (string $key, array $replace = []): string => (string) $this->translator->get('larena-env::admin.'.$key, $replace);
        $href = $warning->domain !== null
            ? route('larena.env.admin.site', ['domain' => $warning->domain], false).'?tab='.$warning->tab()
            : route('larena.env.admin.overview', [], false);
        $acknowledge = $canAcknowledge && $warning->acknowledgeable() && $warning->alertId !== null
            ? new AdminAlertAction($text('warnings.ack'), route('larena.env.admin.alerts.ack.open', [], false).'?'.http_build_query(['id' => $warning->alertId, 'back' => self::panelBack($back)]))
            : null;

        return new AdminAlert(
            id: self::id($warning),
            level: $warning->severity === PanelWarning::ERROR ? AdminAlertLevel::Danger : AdminAlertLevel::Warning,
            text: $text('warnings.'.$warning->kind, $warning->replace),
            source: $warning->domain !== null ? $text('warnings.bar_site', ['site' => $warning->domain]) : $text('warnings.bar_server'),
            action: new AdminAlertAction($text('warnings.go'), $href),
            acknowledge: $acknowledge,
        );
    }

    /** The condition's name in the bar: the server's alert by its id, else the panel's warning by what it is about. */
    public static function id(PanelWarning $warning): string
    {
        $name = $warning->alertId !== null ? 'alert:'.$warning->alertId : $warning->kind.':'.($warning->domain ?? $warning->subject ?? 'server');
        $name = 'env:'.strtolower((string) preg_replace('/[^A-Za-z0-9._:-]+/', '-', $name));

        return substr($name, 0, 191);
    }

    /** Back to where the person was when it is a panel page; the acknowledgement's own check keeps it inside the panel. */
    private static function panelBack(string $uri): string
    {
        return preg_match('/\A\/[A-Za-z0-9\/._~?=&%+-]{0,500}\z/D', $uri) === 1 ? $uri : '/';
    }
}
