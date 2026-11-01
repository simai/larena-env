<?php

declare(strict_types=1);

namespace Larena\Env\Http\Controllers;

use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Contracts\View\Factory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Larena\Env\Http\Middleware\ResolvePanelActor;
use Larena\Env\Http\Support\NoticeRedirect;
use Larena\Env\Operations\PanelOperations;
use Larena\Env\Server\ServerOverviewReader;
use Larena\Env\Server\SiteExtrasReader;
use Throwable;

/**
 * The server's own alerts (simai-env 1.31.0): a person acknowledges one they have seen, at either level (an owner only
 * their sites' ones, which the server checks), and the system level chooses where the server sends them — a webhook,
 * a Telegram chat, or nowhere — and sends a test. Every change goes by plan and confirmation. A Telegram bot's token
 * never reaches a page: between the plan and the confirmation it waits encrypted in the person's session, named by a
 * random reference, and is dropped once sent.
 */
final readonly class AlertsController
{
    private const string TOKENS = 'larena_env_alert_tokens';
    private const int TOKEN_SECONDS = 900;

    public function __construct(
        private PanelOperations $operations,
        private ServerOverviewReader $overviews,
        private SiteExtrasReader $extras,
        private Factory $views,
        private Translator $translator,
        private StringEncrypter $encrypter,
    ) {
    }

    public function ackPlan(Request $request): mixed
    {
        $id = self::alertId($request);
        $back = self::back($request);
        if ($id === null) {
            return $this->notice($request, $back, 'invalid_argument');
        }
        $result = $this->operations->planCommand(ResolvePanelActor::of($request), 'self:alerts-ack', ['id' => $id]);
        if ($result->plan === null) {
            return $this->notice($request, $back, $result->reason ?? 'answer_shape_invalid');
        }

        return $this->views->make('larena-env::admin.plan', [
            'heading' => $this->text('alerts.ack_heading'),
            'eyebrow' => $id,
            'target' => $id,
            'source' => $result->source,
            'plan' => $result->plan,
            'runUrl' => route('larena.env.admin.alerts.ack.run', [], false),
            'cancelUrl' => $back,
            'hidden' => ['id' => $id, 'back' => $back],
        ]);
    }

    public function ackRun(Request $request): RedirectResponse
    {
        $id = self::alertId($request);
        $back = self::back($request);
        if ($id === null) {
            return $this->notice($request, $back, 'invalid_argument');
        }
        $started = $this->operations->startCommand(ResolvePanelActor::of($request), 'self:alerts-ack', ['id' => $id]);

        return $started->jobId === null
            ? $this->notice($request, $back, $started->reason ?? 'answer_shape_invalid')
            : new RedirectResponse(route('larena.env.admin.job', ['job' => $started->jobId], false));
    }

    /** «Настройки панели › Оповещения»: where the server sends its alerts now, and the form to change it. */
    public function channel(Request $request): mixed
    {
        $actor = ResolvePanelActor::of($request);
        $overview = $this->overviews->read($actor)->overview;
        $capabilities = $overview?->capabilities;
        $alerts = $capabilities?->allows('self:alerts') === true ? $this->extras->alerts($actor) : null;

        return $this->views->make('larena-env::admin.alerts', [
            'supported' => $capabilities?->allows('self:alerts-channel') === true,
            'canTest' => $capabilities?->allows('self:alerts-test') === true,
            'channel' => $alerts?->channel,
            'notice' => $request->session()->get('larena_env_notice'),
        ]);
    }

    public function channelPlan(Request $request): mixed
    {
        $input = $this->channelInput($request);
        if ($input === null) {
            return $this->notice($request, route('larena.env.admin.alerts.channel', [], false), 'invalid_argument');
        }
        [$args, $hidden] = $input;
        $result = $this->operations->planCommand(ResolvePanelActor::of($request), 'self:alerts-channel', $args);
        if ($result->plan === null) {
            $this->dropToken($request, $hidden['token_ref'] ?? null);

            return $this->notice($request, route('larena.env.admin.alerts.channel', [], false), $result->reason ?? 'answer_shape_invalid');
        }

        return $this->views->make('larena-env::admin.plan', [
            'heading' => $this->text('alerts.channel_heading'),
            'eyebrow' => $this->text('alerts.kinds.'.$args['kind']),
            'target' => $this->text('alerts.kinds.'.$args['kind']),
            'source' => $result->source,
            'plan' => $result->plan,
            'runUrl' => route('larena.env.admin.alerts.channel.run', [], false),
            'cancelUrl' => route('larena.env.admin.alerts.channel', [], false),
            'hidden' => $hidden,
        ]);
    }

    public function channelRun(Request $request): RedirectResponse
    {
        $kind = (string) $request->input('kind');
        $args = ['kind' => in_array($kind, ['webhook', 'telegram', 'off'], true) ? $kind : 'off'];
        $target = trim((string) $request->input('target'));
        if ($args['kind'] !== 'off' && $target !== '') {
            $args['target'] = $target;
        }
        $ref = $request->input('token_ref');
        if ($args['kind'] === 'telegram' && is_string($ref) && $ref !== '') {
            $token = $this->takeToken($request, $ref);
            if ($token === null) {
                return $this->notice($request, route('larena.env.admin.alerts.channel', [], false), 'token_missing');
            }
            $args['token'] = $token;
        }
        $started = $this->operations->startCommand(ResolvePanelActor::of($request), 'self:alerts-channel', $args);

        return $started->jobId === null
            ? $this->notice($request, route('larena.env.admin.alerts.channel', [], false), $started->reason ?? 'answer_shape_invalid')
            : new RedirectResponse(route('larena.env.admin.job', ['job' => $started->jobId], false));
    }

    public function testPlan(Request $request): mixed
    {
        $result = $this->operations->planCommand(ResolvePanelActor::of($request), 'self:alerts-test', []);
        if ($result->plan === null) {
            return $this->notice($request, route('larena.env.admin.alerts.channel', [], false), $result->reason ?? 'answer_shape_invalid');
        }

        return $this->views->make('larena-env::admin.plan', [
            'heading' => $this->text('alerts.test'),
            'eyebrow' => $this->text('alerts.title'),
            'target' => $this->text('alerts.title'),
            'source' => $result->source,
            'plan' => $result->plan,
            'runUrl' => route('larena.env.admin.alerts.test.run', [], false),
            'cancelUrl' => route('larena.env.admin.alerts.channel', [], false),
            'hidden' => [],
        ]);
    }

    public function testRun(Request $request): RedirectResponse
    {
        $started = $this->operations->startCommand(ResolvePanelActor::of($request), 'self:alerts-test', []);

        return $started->jobId === null
            ? $this->notice($request, route('larena.env.admin.alerts.channel', [], false), $started->reason ?? 'answer_shape_invalid')
            : new RedirectResponse(route('larena.env.admin.job', ['job' => $started->jobId], false));
    }

    /**
     * The gateway arguments of the channel form, and what its confirmation carries back: never the token itself.
     *
     * @return array{0: array<string, string>, 1: array<string, string>}|null
     */
    private function channelInput(Request $request): ?array
    {
        $kind = (string) $request->input('kind');
        if (!in_array($kind, ['webhook', 'telegram', 'off'], true)) {
            return null;
        }
        $args = ['kind' => $kind];
        $hidden = ['kind' => $kind];
        if ($kind === 'off') {
            return [$args, $hidden];
        }
        $target = trim((string) $request->input('target'));
        if ($target === '') {
            return null;
        }
        $args['target'] = $target;
        $hidden['target'] = $target;
        $token = trim((string) $request->input('token'));
        if ($kind === 'telegram' && $token !== '') {
            $args['token'] = $token;
            $hidden['token_ref'] = $this->keepToken($request, $token);
        }

        return [$args, $hidden];
    }

    private function keepToken(Request $request, string $token): string
    {
        $ref = bin2hex(random_bytes(16));
        $kept = array_filter((array) $request->session()->get(self::TOKENS, []),
            static fn (mixed $entry): bool => is_array($entry) && is_int($entry['at'] ?? null) && $entry['at'] > time() - self::TOKEN_SECONDS);
        $kept[$ref] = ['at' => time(), 'token' => $this->encrypter->encryptString($token)];
        $request->session()->put(self::TOKENS, $kept);

        return $ref;
    }

    private function takeToken(Request $request, string $ref): ?string
    {
        $kept = (array) $request->session()->get(self::TOKENS, []);
        $entry = $kept[$ref] ?? null;
        unset($kept[$ref]);
        $request->session()->put(self::TOKENS, $kept);
        if (!is_array($entry) || !is_int($entry['at'] ?? null) || $entry['at'] <= time() - self::TOKEN_SECONDS || !is_string($entry['token'] ?? null)) {
            return null;
        }
        try {
            return $this->encrypter->decryptString($entry['token']);
        } catch (Throwable) {
            return null;
        }
    }

    private function dropToken(Request $request, ?string $ref): void
    {
        if ($ref !== null) {
            $this->takeToken($request, $ref);
        }
    }

    private static function alertId(Request $request): ?string
    {
        $id = $request->input('id');

        return is_string($id) && preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/D', $id) === 1 ? $id : null;
    }

    /** Where the person came from, inside the panel only; the sites' list otherwise. */
    private static function back(Request $request): string
    {
        $back = $request->input('back');
        $prefix = route('larena.env.admin.index', [], false);

        return is_string($back) && str_starts_with($back, rtrim($prefix, '/')) && !str_starts_with($back, '//')
            && preg_match('/\A\/[A-Za-z0-9\/._~?=&%+-]{0,500}\z/D', $back) === 1 ? $back : $prefix;
    }

    private function notice(Request $request, string $to, string $reason): RedirectResponse
    {
        return NoticeRedirect::to($request, $to, $this->reasonText($reason));
    }

    private function text(string $key): string
    {
        return (string) $this->translator->get('larena-env::admin.'.$key);
    }

    private function reasonText(string $reason): string
    {
        $key = 'larena-env::admin.reasons.'.$reason;
        $line = (string) $this->translator->get($key);

        return $line !== $key ? $line : (string) $this->translator->get('larena-env::admin.reasons.unknown', ['reason' => $reason]);
    }
}
