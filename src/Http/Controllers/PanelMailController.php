<?php

declare(strict_types=1);

namespace Larena\Env\Http\Controllers;

use Closure;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Contracts\View\Factory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Larena\Access\Runtime\AccessOperationAuthorizer;
use Larena\Admin\Runtime\AdminPlannedAction;
use Larena\Env\Http\Middleware\ResolvePanelActor;
use Larena\Env\Http\Support\NoticeRedirect;
use Larena\Env\Http\Support\PlanReceipts;
use Larena\Env\Mail\MailFailed;
use Larena\Env\Mail\MailLetters;
use Larena\Env\Mail\MailSettings;
use Larena\Env\Mail\PanelMail;
use Larena\Env\Mail\PanelMailer;
use Larena\Env\Server\Moment;
use Throwable;

/**
 * «Настройки» → «Почта» (owner decision C335, step 1): how the panel sends its own letters — an SMTP server or a mail
 * service — kept as one card «значение · Изменить», changed in place, planned in words and confirmed. The password is
 * never shown again; between the plan and its confirmation it waits encrypted in the person's session. A test letter
 * goes to the person's own address and, once it leaves, switches mail on; until then «Забыли пароль?» keeps saying
 * whom to ask.
 */
final readonly class PanelMailController
{
    /** One test letter at a time: the next one 10 seconds after the last (the wait of one letter at most). */
    private const string TESTED = 'larena_env_mail_tested_at';
    private const int TEST_GAP_SECONDS = 10;

    /** @param (Closure(Request): ?string)|null $email the person's own address, where the test letter goes */
    public function __construct(
        private PanelMail $mail,
        private PanelMailer $mailer,
        private Factory $views,
        private Translator $translator,
        private StringEncrypter $encrypter,
        private ?AccessOperationAuthorizer $authorizer = null,
        private ?Closure $email = null,
        private ?MailLetters $letters = null,
        private PlanReceipts $receipts = new PlanReceipts(),
    ) {
    }

    public function index(Request $request): mixed
    {
        $settings = $this->mail->settings();
        $state = $this->mail->state();
        $canOperate = $this->authorizer?->authorize($request, 'env.server.operate')->isAllowed() ?? true;
        $email = $this->personEmail($request);

        return $this->views->make('larena-env::admin.mail', [
            'state' => $state,
            'settings' => $settings,
            'items' => $this->items($settings, $state, $canOperate),
            'checked' => $settings === null ? null : self::checkedMoment($this->mail->checkedAt()),
            'mismatch' => $settings?->senderMismatch() === true,
            'canOperate' => $canOperate,
            'canTest' => $canOperate && $settings !== null && $this->mail->hasPassword() && $email !== null,
            'testTo' => $email,
            'hasPassword' => $this->mail->hasPassword(),
            'edit' => $canOperate && ($request->query('edit') === 'mail' || $settings === null || $state === PanelMail::PASSWORD_LOST),
            'services' => array_merge([MailSettings::SMTP], array_keys(MailSettings::SERVICES)),
            'notice' => $request->session()->get('larena_env_notice'),
        ]);
    }

    /** The change in words: what will be kept, never the password itself, and what happens to mail after it. */
    public function plan(Request $request): mixed
    {
        $back = $this->page();
        if ($request->input('action') === 'off') {
            if ($this->mail->state() !== PanelMail::ON) {
                return $this->notice($request, $back, 'nothing_to_change');
            }

            return $this->showPlan($request, ['action' => 'off'], $this->text('plan.off_heading'), [], [], $this->text('plan.off_consequence'));
        }
        $settings = MailSettings::fromForm($request->all());
        if (is_string($settings)) {
            return $this->notice($request, $back.'?edit=mail#env-mail-now', 'invalid.'.$settings);
        }
        $password = $request->input('password');
        $password = is_string($password) && $password !== '' ? $password : null;
        if ($password === null && !$this->mail->hasPassword()) {
            return $this->notice($request, $back.'?edit=mail#env-mail-now', 'password_required');
        }
        if ($password !== null && (strlen($password) > 500 || preg_match('/[\x00-\x1f\x7f]/', $password) === 1)) {
            return $this->notice($request, $back.'?edit=mail#env-mail-now', 'password_invalid');
        }
        $before = $this->mail->settings();
        $retest = $before === null || $password !== null || !$before->sameDelivery($settings);
        if (!$retest && $before->levels === $settings->levels) {
            return $this->notice($request, $back, 'nothing_to_change');
        }
        $facts = [
            [$this->text('fields.service'), $this->text('services.'.$settings->service)],
            [$this->text('fields.server'), $this->server($settings)],
            [$this->text('fields.login'), $settings->login],
            [$this->text('fields.password'), $this->text($password !== null ? 'plan.password_new' : 'plan.password_kept')],
            [$this->text('fields.from'), $this->from($settings)],
            [$this->text('fields.levels'), $this->levels($settings)],
        ];
        $warnings = $settings->senderMismatch() ? [['text' => $this->text('spam_warning'), 'blocking' => false]] : [];
        $after = $retest ? $this->text($this->mail->isOn() ? 'plan.after_retest_on' : 'plan.after_retest') : $this->text('plan.after_same');

        return $this->showPlan($request, ['action' => 'save', 'settings' => $settings->toRow(),
            'password' => $password === null ? null : $this->encrypter->encryptString($password)], $this->text('plan.heading'), $facts, $warnings, $after);
    }

    public function run(Request $request): RedirectResponse
    {
        $back = $this->page();
        $plan = $this->takePlan($request);
        if ($plan === null) {
            return $this->notice($request, $back, 'plan_expired');
        }
        $actor = $this->person($request);
        if ($plan['action'] === 'off') {
            $this->mail->switchOff($actor);

            return NoticeRedirect::to($request, $back, $this->text('done.off'));
        }
        $settings = MailSettings::fromForm($plan['settings'] + ['levels' => explode(',', (string) ($plan['settings']['levels'] ?? ''))]);
        if (is_string($settings)) {
            return $this->notice($request, $back, 'plan_expired');
        }
        try {
            $password = is_string($plan['password'] ?? null) ? $this->encrypter->decryptString($plan['password']) : null;
            $state = $this->mail->save($settings, $password, $actor);
        } catch (MailFailed $failed) {
            return $this->notice($request, $back.'?edit=mail#env-mail-now', $failed->reason);
        } catch (Throwable) {
            return $this->notice($request, $back, 'plan_expired');
        }

        return NoticeRedirect::to($request, $back, $this->text($state === PanelMail::UNCHECKED ? 'done.saved_test' : 'done.saved'));
    }

    /**
     * «Отправить проверочное письмо»: to the person's own address, at once, at most 10 seconds; the answer in words.
     * The letter leaving switches mail on: that is what the plan of the settings said would happen.
     */
    public function test(Request $request): RedirectResponse
    {
        $back = $this->page();
        $settings = $this->mail->settings();
        $to = $this->personEmail($request);
        if ($settings === null || $to === null) {
            return $this->notice($request, $back, $to === null ? 'no_address' : 'not_set_up');
        }
        $password = $this->mail->password();
        if ($password === null) {
            return $this->notice($request, $back.'?edit=mail#env-mail-now', 'password_lost');
        }
        $last = $request->session()->get(self::TESTED);
        if (is_int($last) && $last > time() - self::TEST_GAP_SECONDS) {
            return $this->notice($request, $back, 'test_wait');
        }
        $request->session()->put(self::TESTED, time());
        $reason = $this->mailer->send($settings, $password, $to, $this->text('letters.test.subject'), $this->text('letters.test.body'));
        // «История» → «Почта»; a test letter that left also ends «Почта не уходит».
        $this->letters?->record('test', $to, $this->text('letters.test.subject'), $reason);
        if ($reason !== null) {
            return NoticeRedirect::to($request, $back, $this->text('test.failed', ['reason' => $this->text('reasons.'.$reason)]));
        }
        $wasOn = $this->mail->isOn();
        $this->mail->checked($this->person($request));

        return NoticeRedirect::to($request, $back, $this->text($wasOn ? 'test.sent' : 'test.sent_on', ['to' => $to]));
    }

    /**
     * The card «Почта панели»: how letters leave, the password only as «задан», when the last test letter left.
     *
     * @return list<array<string, mixed>>
     */
    private function items(?MailSettings $settings, string $state, bool $canEdit): array
    {
        $items = [['type' => 'section', 'id' => 'mail', 'label' => $this->text('card.title')]
            + ($canEdit ? ['action' => ['label' => $this->text('card.edit'), 'href' => '?edit=mail#env-mail-now']] : [])];
        $items[] = ['id' => 'state', 'label' => $this->text('card.state'), 'value' => $this->text('states.'.$state),
            'status' => match ($state) {
                PanelMail::ON => 'success',
                PanelMail::PASSWORD_LOST => 'error',
                default => 'warning',
            }] + ($state === PanelMail::UNCHECKED ? ['hint' => $this->text('states.unchecked_hint')] : [])
            + ($state === PanelMail::NONE ? ['hint' => $this->text('states.none_hint')] : []);
        if ($settings === null) {
            return $items;
        }
        $checked = $this->mail->checkedAt();

        return [...$items,
            ['id' => 'service', 'label' => $this->text('fields.service'), 'value' => $this->text('services.'.$settings->service)],
            ['id' => 'server', 'label' => $this->text('fields.server'), 'value' => $this->server($settings)],
            ['id' => 'login', 'label' => $this->text('fields.login'), 'value' => $settings->login],
            ['id' => 'password', 'label' => $this->text('fields.password'), 'value' => $this->text($this->mail->hasPassword() ? 'card.password_set' : 'card.password_missing')]
                + ($this->mail->hasPassword() ? [] : ['status' => 'error']),
            ['id' => 'from', 'label' => $this->text('fields.from'), 'value' => $this->from($settings)]
                + ($settings->senderMismatch() ? ['status' => 'warning', 'hint' => $this->text('spam_warning')] : []),
            ['id' => 'levels', 'label' => $this->text('fields.levels'), 'value' => $this->levels($settings), 'hint' => $this->text('card.levels_hint')],
            // The last test letter's time is the reader's own (C183): a set time goes under the card as the Framework's
            // sf-time (the description list takes only text), so here only «Не было».
            ...(self::checkedMoment($checked) === null ? [['id' => 'checked', 'label' => $this->text('card.checked'), 'value' => $checked === null ? $this->text('card.never') : Moment::human($checked)]] : []),
        ];
    }

    /** The last test letter's moment as ISO with its offset for sf-time to say in the reader's zone (C183), or null. */
    public static function checkedMoment(?string $checked): ?string
    {
        return Moment::iso($checked);
    }

    /**
     * @param array<string, mixed> $kept what the confirmation makes: the settings and the password encrypted
     * @param list<array{0: string, 1: string}> $facts
     * @param list<array{text: string, blocking: bool}> $warnings
     */
    private function showPlan(Request $request, array $kept, string $heading, array $facts, array $warnings, string $consequence): mixed
    {
        // The change waits in the plan's receipt (PlanReceipts), the password encrypted: the confirmation sends back only its id.
        $id = $this->receipts->issue($request, 'mail:settings', [], $kept);
        $run = route('larena.env.admin.settings.mail.run', [], false);

        return $this->views->make('larena-env::admin.mail-plan', [
            'heading' => $heading,
            'action' => new AdminPlannedAction(
                question: $heading.'?',
                runUrl: $run,
                token: (string) $request->session()->token(),
                runFields: ['plan' => $id],
                acceptText: $kept['action'] === 'off' ? $this->text('plan.off_accept') : $this->text('plan.accept'),
                consequence: $consequence,
                facts: $facts,
                warnings: $warnings,
                cancelUrl: $this->page(),
                key: 'env-mail',
            ),
        ]);
    }

    /** @return array<string, mixed>|null the plan confirmed, once, while it is fresh */
    private function takePlan(Request $request): ?array
    {
        $plan = $this->receipts->take($request, 'mail:settings', 'plan');
        if (!is_array($plan) || !in_array($plan['action'] ?? null, ['save', 'off'], true) || ($plan['action'] === 'save' && !is_array($plan['settings'] ?? null))) {
            return null;
        }

        return $plan;
    }

    private function server(MailSettings $settings): string
    {
        return $settings->host.':'.$settings->port.' · '.$this->text('encryptions.'.$settings->encryption);
    }

    private function from(MailSettings $settings): string
    {
        return $settings->fromName.' <'.$settings->fromAddress.'>';
    }

    private function levels(MailSettings $settings): string
    {
        return $settings->levels === [] ? $this->text('levels.none')
            : implode(', ', array_map(fn (string $level): string => $this->text('levels.'.$level), $settings->levels));
    }

    private function personEmail(Request $request): ?string
    {
        return $this->email !== null ? ($this->email)($request) : SiteAdminController::personEmail($request);
    }

    /** The person who did it, also when they look at the panel as someone else. */
    private function person(Request $request): string
    {
        $actor = ResolvePanelActor::of($request);

        return $actor->impersonatedBy ?? $actor->user;
    }

    private function page(): string
    {
        return route('larena.env.admin.settings.mail', [], false);
    }

    private function notice(Request $request, string $to, string $reason): RedirectResponse
    {
        return NoticeRedirect::to($request, $to, $this->text('reasons.'.$reason));
    }

    /** @param array<string, string> $replace */
    private function text(string $key, array $replace = []): string
    {
        return (string) $this->translator->get('larena-env::mail.'.$key, $replace);
    }
}
