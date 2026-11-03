<?php

declare(strict_types=1);

namespace Larena\Env\Http\Controllers;

use Illuminate\Contracts\Translation\Translator;
use Illuminate\Contracts\View\Factory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Larena\Env\Certificates\DnsCertificates;
use Larena\Env\Dns\DnsConnection;
use Larena\Env\Http\Middleware\ResolvePanelActor;
use Larena\Env\Http\Support\NoticeRedirect;
use Larena\Env\Operations\PanelOperations;

/**
 * Certificates through DNS (simai-env 1.36.0), on «DNS › Подключение»: the Cloudflare token the panel is connected with
 * is given to the server, which keeps it root-only and checks a certificate's domain with a DNS record; or the server's
 * copy is removed. The administrator's alone, planned and confirmed. The token is read from the panel's connection at
 * each step and never travels through the page.
 */
final readonly class DnsCertificatesController
{
    private const string COMMAND = 'ssl:dns-credentials';
    /** The tokens the server takes: a Cloudflare API token, letters, digits, dash and underscore. */
    private const string TOKEN = '/\A[A-Za-z0-9_-]{30,64}\z/D';

    public function __construct(
        private PanelOperations $operations,
        private DnsConnection $connection,
        private Factory $views,
        private Translator $translator,
    ) {
    }

    public function plan(Request $request): mixed
    {
        $op = self::op($request);
        $args = $op === null ? null : $this->args($op);
        if (!is_array($args)) {
            return $this->notice($request, $args ?? 'invalid_argument');
        }
        $result = $this->operations->planCommand(ResolvePanelActor::of($request), self::COMMAND, $args);
        if ($result->plan === null) {
            return $this->notice($request, $result->reason ?? 'answer_shape_invalid');
        }

        return $this->views->make('larena-env::admin.plan', [
            'heading' => $this->text('dns.certificates.'.$op.'_heading'),
            'eyebrow' => $this->text('dns.certificates.title'),
            'target' => $this->text('dns.providers.'.DnsCertificates::CLOUDFLARE),
            'source' => $result->source,
            'plan' => $result->plan,
            'runUrl' => route('larena.env.admin.dns.certificates.run', [], false),
            'cancelUrl' => self::back(),
            'hidden' => ['op' => $op],
        ]);
    }

    public function run(Request $request): RedirectResponse
    {
        $op = self::op($request);
        $args = $op === null ? null : $this->args($op);
        if (!is_array($args)) {
            return $this->notice($request, $args ?? 'invalid_argument');
        }
        $started = $this->operations->startCommand(ResolvePanelActor::of($request), self::COMMAND, $args);

        return $started->jobId === null
            ? $this->notice($request, $started->reason ?? 'answer_shape_invalid')
            : new RedirectResponse(route('larena.env.admin.job', ['job' => $started->jobId], false));
    }

    /** @return 'store'|'remove'|null */
    private static function op(Request $request): ?string
    {
        $op = $request->input('op');

        return $op === 'store' || $op === 'remove' ? $op : null;
    }

    /**
     * The gateway's arguments, or why there are none: the panel is not connected to Cloudflare, or its token is not one
     * the server takes.
     *
     * @param 'store'|'remove' $op
     *
     * @return array<string, string>|string
     */
    private function args(string $op): array|string
    {
        if ($op === 'remove') {
            return ['provider' => DnsCertificates::CLOUDFLARE, 'remove' => 'yes'];
        }
        $connected = $this->connection->current();
        $token = $connected?->provider === DnsCertificates::CLOUDFLARE ? $this->connection->token() : null;
        if ($token === null) {
            return 'dns_not_connected';
        }

        return preg_match(self::TOKEN, $token) === 1 ? ['provider' => DnsCertificates::CLOUDFLARE, 'token' => $token] : 'dns_token_unsuitable';
    }

    private static function back(): string
    {
        return route('larena.env.admin.dns.connection', [], false).'#env-dns-certificates';
    }

    private function notice(Request $request, string $reason): RedirectResponse
    {
        $key = 'larena-env::admin.reasons.'.$reason;
        $line = (string) $this->translator->get($key);

        return NoticeRedirect::to($request, self::back(), $line !== $key ? $line
            : (string) $this->translator->get('larena-env::admin.reasons.unknown', ['reason' => $reason]));
    }

    private function text(string $key): string
    {
        return (string) $this->translator->get('larena-env::admin.'.$key);
    }
}
