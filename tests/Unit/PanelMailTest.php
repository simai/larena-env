<?php

declare(strict_types=1);

// The panel's own mail (owner decision C335, step 1): «Настройки» → «Почта» with an SMTP server or a service, the
// password kept sealed and never shown, a test letter that switches mail on, «Что слать», and the sign-in package's
// postman that exists only while mail is on. The auditor's three additions: the console's way when there is nobody to
// ask (`larena:panel-reset-password`), the spam warning for a sender off the mailbox's domain, and a panel restored
// without its key — mail switches itself off and asks for the password again, no error page. No letter leaves this
// test: the transport is a stand-in that remembers what it was given.

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Carbon;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\Engines\CompilerEngine;
use Illuminate\View\Engines\EngineResolver;
use Illuminate\View\Factory;
use Illuminate\View\FileViewFinder;
use Larena\Audit\Contracts\AuditSink;
use Larena\Audit\Runtime\InMemoryAuditSink;
use Larena\Auth\ValueObjects\PasswordResetDeliveryMessage;
use Larena\Env\Gateway\PanelActor;
use Larena\Env\Http\Controllers\PanelMailController;
use Larena\Env\Http\Middleware\ResolvePanelActor;
use Larena\Env\Mail\MailFailed;
use Larena\Env\Mail\MailSettings;
use Larena\Env\Mail\PanelMail;
use Larena\Env\Mail\PanelMailer;
use Larena\Env\Mail\PanelPasswordResetDelivery;
use Larena\Env\People\ConsolePasswordReset;
use Larena\Env\People\PanelAudit;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

require_once dirname(__DIR__).'/Support/env-test.php';
require_once dirname(__DIR__).'/Support/AdminPresenceHost.php';

$lines = ['admin' => require dirname(__DIR__, 2).'/resources/lang/en/admin.php', 'mail' => require dirname(__DIR__, 2).'/resources/lang/en/mail.php'];
$russian = ['admin' => require dirname(__DIR__, 2).'/resources/lang/ru/admin.php', 'mail' => require dirname(__DIR__, 2).'/resources/lang/ru/mail.php'];

/** @param array<string, string> $replace */
function env_mail_line(array $lines, string $key, array $replace = []): mixed
{
    if (preg_match('/\Alarena-env::(admin|mail)\.(.+)\z/', $key, $found) !== 1) {
        return $key;
    }
    $line = data_get($lines[$found[1]], $found[2]);
    if (!is_string($line)) {
        return is_array($line) ? $line : $key;
    }
    foreach ($replace as $name => $value) {
        $line = str_replace(':'.$name, (string) $value, $line);
    }

    return $line;
}

/** @param array<string, string> $replace */
function __(string $key, array $replace = []): mixed
{
    global $lines;

    return env_mail_line($lines, $key, $replace);
}

/** @param array<string, mixed> $parameters */
function route(string $name, array $parameters = [], bool $absolute = true): string
{
    return '/admin/server/'.$name;
}

function csrf_field(): string
{
    return '<input type="hidden" name="_token" value="test">';
}

function csrf_token(): string
{
    return 'test';
}

/** A mail server stand-in: remembers each letter, or refuses as a real server words it. */
final class CaughtTransport implements TransportInterface
{
    /** @var list<Email> */
    private array $letters = [];

    public ?string $refuse = null;

    public function send(RawMessage $message, ?Envelope $envelope = null): SentMessage
    {
        if ($this->refuse !== null) {
            throw new TransportException($this->refuse);
        }
        if ($message instanceof Email) {
            $this->letters[] = $message;
        }

        return new SentMessage($message, $envelope ?? Envelope::create($message));
    }

    /** @return list<Email> */
    public function letters(): array
    {
        return $this->letters;
    }

    public function clear(): void
    {
        $this->letters = [];
    }

    public function __toString(): string
    {
        return 'caught://';
    }
}

/** The app key's stand-in; another key cannot open what this one sealed. */
final class KeyedEncrypter implements Illuminate\Contracts\Encryption\StringEncrypter
{
    public function __construct(private readonly string $key)
    {
    }

    public function encryptString(#[\SensitiveParameter] $value): string
    {
        return 'sealed:'.$this->key.':'.base64_encode(strrev((string) $value));
    }

    public function decryptString($payload): string
    {
        $prefix = 'sealed:'.$this->key.':';
        if (!str_starts_with((string) $payload, $prefix)) {
            throw new Illuminate\Contracts\Encryption\DecryptException('The MAC is invalid.');
        }

        return strrev((string) base64_decode(substr((string) $payload, strlen($prefix))));
    }
}

$host = new AdminPresenceHost();
Container::setInstance($host);
$sink = new InMemoryAuditSink();
$host->instance(AuditSink::class, $sink);
$audit = new PanelAudit($host);

$capsule = new Capsule();
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$db = $capsule->getConnection();
$host->instance('db.schema', $db->getSchemaBuilder());
Illuminate\Support\Facades\Facade::setFacadeApplication($host);
(require dirname(__DIR__, 2).'/database/migrations/2026_11_18_000007_create_larena_env_mail_table.php')->up();

$refuses = static function (callable $call, string $reason): bool {
    try {
        $call();
    } catch (MailFailed $failed) {
        return $failed->reason === $reason;
    }

    return false;
};

// 1. The form: a service fills its own server; plain SMTP only to this machine; urgent only unless more is ticked.
$yandex = MailSettings::fromForm(['service' => 'yandex', 'host' => 'evil.example', 'port' => '25', 'encryption' => 'none', 'login' => 'panel@example.ru',
    'from_address' => 'panel@example.ru', 'from_name' => '', 'levels' => ['urgent']]);
env_assert($yandex instanceof MailSettings && $yandex->host === 'smtp.yandex.ru' && $yandex->port === 465 && $yandex->encryption === 'ssl'
    && $yandex->fromName === 'Larena Panel' && $yandex->levels === ['urgent'], 'Яндекс 360: its own server, port and SSL whatever the form says; the default sender name.');
$sendgrid = MailSettings::fromForm(['service' => 'sendgrid', 'login' => '', 'from_address' => 'noreply@shop.example', 'levels' => []]);
env_assert($sendgrid instanceof MailSettings && $sendgrid->login === 'apikey' && $sendgrid->encryption === 'tls' && $sendgrid->levels === [], 'SendGrid: the login is «apikey», STARTTLS on 587.');
env_assert(MailSettings::fromForm(['service' => 'smtp', 'host' => 'mail.example.com', 'port' => '25', 'encryption' => 'none', 'login' => 'a@example.com', 'from_address' => 'a@example.com']) === 'encryption_none',
    'Plain SMTP across a network is refused: the password would travel open.');
$local = MailSettings::fromForm(['service' => 'smtp', 'host' => '127.0.0.1', 'port' => '2525', 'encryption' => 'none', 'login' => 'stand@panel.test', 'from_address' => 'stand@panel.test', 'levels' => ['urgent', 'warnings', 'bogus']]);
env_assert($local instanceof MailSettings && $local->levels === ['urgent', 'warnings'], 'Plain SMTP to this machine (a local test catcher) is allowed; unknown levels dropped.');
foreach (['host' => ['host' => 'not a host'], 'port' => ['port' => '70000'], 'login' => ['login' => ''], 'from_address' => ['from_address' => 'nobody'],
    'from_name' => ['from_name' => 'Evil <x@y>'], 'service' => ['service' => 'postfix']] as $field => $wrong) {
    $form = array_merge(['service' => 'smtp', 'host' => 'smtp.example.com', 'port' => '587', 'encryption' => 'tls', 'login' => 'a@example.com', 'from_address' => 'a@example.com'], $wrong);
    env_assert(MailSettings::fromForm($form) === $field, "A wrong {$field} is named.");
}

// 2. Auditor's addition 2: the sender off the mailbox's domain is said; a sending service checks it itself.
$mismatch = MailSettings::fromForm(['service' => 'mailru', 'login' => 'box@corp.ru', 'from_address' => 'noreply@shop.example']);
env_assert($mismatch instanceof MailSettings && $mismatch->senderMismatch(), '«От кого» on another domain than the mailbox: the spam warning.');
env_assert(!$yandex->senderMismatch() && !$sendgrid->senderMismatch(), 'The same domain, or a sending service: no warning.');
env_assert($russian['mail']['spam_warning'] === 'Письма могут попасть в спам: адрес «От кого» не на домене почтового ящика.', 'The warning in the auditor\'s words.');

// 3. The store: the password sealed, never in a row in the clear; mail waits for a test letter.
$key = new KeyedEncrypter('key-a');
$mail = new PanelMail($db, $key, $audit);
env_assert($mail->state() === PanelMail::NONE && !$mail->isOn() && $mail->settings() === null, 'Nothing set up: «Не настроена».');
env_assert($refuses(static fn () => $mail->save($yandex, null, 'user:admin_identity:1'), 'password_required'), 'The first save needs the password.');
$secret = 'mailbox-secret-Pa55!';
env_assert($mail->save($yandex, $secret, 'user:admin_identity:1') === PanelMail::UNCHECKED && !$mail->isOn(), 'Saved: off until a test letter.');
$row = (array) $db->table(PanelMail::TABLE)->first();
env_assert(!str_contains(json_encode($row, JSON_THROW_ON_ERROR), $secret) && $mail->password() === $secret && $mail->hasPassword(), 'The password is sealed and opens only to send.');
$mail->checked('user:admin_identity:1');
env_assert($mail->isOn() && $mail->checkedAt() !== null, 'A test letter left: mail is on.');
$checkedMoment = \Larena\Env\Http\Controllers\PanelMailController::checkedMoment($mail->checkedAt());
env_assert(is_string($checkedMoment) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $checkedMoment) === 1,
    'The last test letter goes to sf-time as a moment with its offset: the reader sees it in their own zone (C183), not «UTC».');
env_assert(\Larena\Env\Http\Controllers\PanelMailController::checkedMoment(null) === null, 'No test letter yet: «Не было» stays in the card.');
$moreLevels = MailSettings::fromForm(['service' => 'yandex', 'login' => 'panel@example.ru', 'from_address' => 'panel@example.ru', 'levels' => ['urgent', 'reports']]);
env_assert($moreLevels instanceof MailSettings && $mail->save($moreLevels, null, 'user:admin_identity:1') === PanelMail::ON && $mail->settings()?->levels === ['urgent', 'reports'],
    '«Что слать» alone changed: mail stays on, no new test.');
env_assert($mail->save($moreLevels, 'another-secret-Pa55!', 'user:admin_identity:1') === PanelMail::UNCHECKED && $mail->checkedAt() === null, 'A new password: the test again.');
$mail->checked('user:admin_identity:1');
$otherSender = MailSettings::fromForm(['service' => 'yandex', 'login' => 'panel@example.ru', 'from_address' => 'other@example.ru', 'levels' => ['urgent', 'reports']]);
env_assert($otherSender instanceof MailSettings && $mail->save($otherSender, null, 'user:admin_identity:1') === PanelMail::UNCHECKED, 'Another sender: the test again.');
$mail->checked('user:admin_identity:1');
$mail->switchOff('user:admin_identity:1');
env_assert($mail->state() === PanelMail::OFF && !$mail->isOn(), 'Switched off by a person.');
$mailEvents = array_values(array_filter($sink->events(), static fn ($event): bool => str_starts_with($event->type, 'env.mail.')));
env_assert(array_map(static fn ($event): string => $event->type, $mailEvents) === ['env.mail.changed', 'env.mail.checked', 'env.mail.changed', 'env.mail.changed', 'env.mail.checked',
    'env.mail.changed', 'env.mail.checked', 'env.mail.switched_off'], '«История» has each change, check and switch.');
foreach ($mailEvents as $event) {
    $said = json_encode([$event->payload, $event->subject], JSON_THROW_ON_ERROR);
    env_assert(!str_contains($said, 'secret') && !str_contains($said, 'example.ru') && !str_contains($said, 'yandex'), 'No value of the settings in «История»: '.$said);
}
env_assert($lines['admin']['people']['history']['env_mail_changed'] === 'Mail settings changed' && $russian['admin']['people']['history']['env_mail_changed'] === 'Изменены настройки почты',
    '«История» says «изменены настройки почты».');

// 4. The sender: the letter as the settings say, the refusals in words, never the server's own message.
$caught = new CaughtTransport();
$given = [];
$mailer = new PanelMailer(static function (MailSettings $settings, string $password) use ($caught, &$given): TransportInterface {
    $given[] = [$settings->host, $password];

    return $caught;
});
env_assert($mailer->send($yandex, $secret, 'admin@example.ru', 'Тема', 'Текст') === null && count($caught->letters()) === 1, 'A letter leaves.');
$letter = $caught->letters()[0];
env_assert($letter->getFrom()[0]->getAddress() === 'panel@example.ru' && $letter->getFrom()[0]->getName() === 'Larena Panel' && $letter->getTo()[0]->getAddress() === 'admin@example.ru'
    && $given === [['smtp.yandex.ru', $secret]], 'From the sender, to the person, through the settings\' server.');
foreach ([
    'auth' => 'Failed to authenticate on SMTP server with username "panel@example.ru" using the following authenticators: "LOGIN". Authenticator "LOGIN" returned "Expected response code "235" but got code "535", with message "535 5.7.8 Error: authentication failed".".',
    'unreachable' => 'Connection could not be established with host "ssl://smtp.yandex.ru:465": stream_socket_client(): Unable to connect to ssl://smtp.yandex.ru:465 (Connection timed out)',
    'sender' => 'Expected response code "250" but got code "553", with message "553 5.7.1 Sender address rejected: not owned by user".',
    'recipient' => 'Expected response codes "250/251/252" but got code "550", with message "550 5.1.1 No such user".',
    'tls' => 'Unable to connect with STARTTLS.',
] as $reason => $message) {
    $caught->refuse = $message;
    env_assert($mailer->send($yandex, $secret, 'admin@example.ru', 'Тема', 'Текст') === $reason, "The refusal said as «{$reason}».");
}
$caught->refuse = null;
env_assert(isset($russian['mail']['reasons']['auth'], $russian['mail']['reasons']['sender'], $russian['mail']['reasons']['unreachable'])
    && str_contains($russian['mail']['reasons']['unreachable'], '10 секунд'), 'Each refusal has its words: «неверный логин или пароль», «не пускает», «не отвечает за 10 секунд».');

// 5. The sign-in package's postman: nothing leaves while mail is off; on, the link to the panel's own address.
$translator = new class ($lines) implements Illuminate\Contracts\Translation\Translator {
    /** @param array<string, array<string, mixed>> $lines */
    public function __construct(private array $lines)
    {
    }

    public function get($key, array $replace = [], $locale = null): mixed
    {
        return env_mail_line($this->lines, (string) $key, $replace);
    }

    public function choice($key, $number, array $replace = [], $locale = null): string
    {
        return (string) $this->get($key, $replace);
    }

    public function getLocale(): string
    {
        return 'en';
    }

    public function setLocale($locale): void
    {
    }
};
$postman = new PanelPasswordResetDelivery($mail, $mailer, $translator,
    static fn (string $challenge, string $secret): string => 'https://panel.example.ru/admin/password/reset/'.rawurlencode($challenge).'?secret='.rawurlencode($secret));
$reset = new PasswordResetDeliveryMessage('admin@example.ru', 'challenge-ref-1', 'reset-secret-1', Carbon::now()->addMinutes(30));
$caught->clear();
env_assert(!$postman->deliver($reset) && $caught->letters() === [], 'Mail off: the link is not sent, the package makes it stop working.');
$mail->checked('user:admin_identity:1');
env_assert($postman->deliver($reset) && count($caught->letters()) === 1, 'Mail on: the reset letter leaves at once.');
$body = (string) $caught->letters()[0]->getTextBody();
env_assert(str_contains($body, 'https://panel.example.ru/admin/password/reset/challenge-ref-1?secret=reset-secret-1') && str_contains($body, '30 min'),
    'The letter carries the one-time link to the panel\'s own address and its 30 minutes.');

// 6. Auditor's addition 3: the panel restored on a server with another key. Mail goes off by itself and asks for the
// password again; nothing throws, nothing is sent, the page says it.
$moved = new PanelMail($db, new KeyedEncrypter('key-b'), $audit);
env_assert($moved->state() === PanelMail::PASSWORD_LOST && !$moved->isOn() && $moved->password() === null && !$moved->hasPassword(), 'Another key: «Почта выключена: введите пароль заново».');
$lost = array_values(array_filter($sink->events(), static fn ($event): bool => $event->type === 'env.mail.password_lost'));
env_assert(count($lost) === 1, 'Switching itself off is in «История», once.');
$moved->state();
env_assert(count(array_filter($sink->events(), static fn ($event): bool => $event->type === 'env.mail.password_lost')) === 1, 'Read again: not written twice.');
$caught->clear();
$movedPostman = new PanelPasswordResetDelivery($moved, $mailer, $translator, static fn (string $challenge, string $secret): string => 'https://panel.example.ru/x');
env_assert(!$movedPostman->deliver($reset) && $caught->letters() === [], 'No letter with a password the key cannot open.');
env_assert($refuses(static fn () => $moved->save($moreLevels, null, 'user:admin_identity:1'), 'password_required'), 'Saving needs the password again.');
env_assert($russian['mail']['states']['password_lost'] === 'Почта выключена: введите пароль заново', 'The state in the auditor\'s words.');

// 7. The screen: the card, the form open by itself while the password is lost, the test, the plan and its confirmation.
$filesystem = new Filesystem();
$resolver = new EngineResolver();
$resolver->register('blade', static fn () => new CompilerEngine(new BladeCompiler($filesystem, env_temp_directory()), $filesystem));
$views = new Factory($resolver, new FileViewFinder($filesystem, []), new Dispatcher());
$views->addExtension('blade.php', 'blade');
$views->addNamespace('larena-admin', [dirname(__DIR__).'/Support/admin-views', dirname(__DIR__, 2).'/vendor/larena/admin/resources/views']);
$views->addNamespace('larena-env', dirname(__DIR__, 2).'/resources/views');
$session = new Store('panel', new ArraySessionHandler(10));
$asking = static function (string $uri, string $method = 'GET', array $input = []) use ($session): Request {
    $request = Request::create($uri, $method, $input);
    $request->attributes->set(ResolvePanelActor::ATTRIBUTE, new PanelActor('user:admin_identity:1'));
    $request->setLaravelSession($session);

    return $request;
};
$notice = static fn (RedirectResponse $response): string => (string) $response->getSession()?->get('larena_env_notice');
$page = static fn ($controller, string $query = ''): string => (string) $controller->index($asking('/admin/server/settings/mail'.$query))->render();
$movedScreen = new PanelMailController($moved, $mailer, $views, $translator, new KeyedEncrypter('key-b'), null, static fn (Request $request): string => 'admin@example.ru');
$lostPage = $page($movedScreen);
env_assert(str_contains($lostPage, 'data-env-mail="password_lost"') && str_contains($lostPage, e($lines['mail']['states']['password_lost'])) && str_contains($lostPage, 'data-env-mail-form')
    && !str_contains($lostPage, 'data-env-mail-test'), 'The page opens (no error), says to enter the password again, and the form is open.');
$testLost = $movedScreen->test($asking('/admin/server/settings/mail/test', 'POST'));
env_assert($notice($testLost) === $lines['mail']['reasons']['password_lost'] && $caught->letters() === [], 'A test with a lost password says so and sends nothing.');

// The password entered again (here with the first key): waits for a test, then on.
env_assert($mail->save($moreLevels, $secret, 'user:admin_identity:1') === PanelMail::UNCHECKED, 'The password entered again: the test again.');
$mail->checked('user:admin_identity:1');
$screen = new PanelMailController($mail, $mailer, $views, $translator, $key, null, static fn (Request $request): string => 'admin@example.ru');
$onPage = $page($screen);
env_assert(str_contains($onPage, 'data-env-mail="on"') && str_contains($onPage, 'data-env-mail-test') && str_contains($onPage, 'data-env-mail-off') && !str_contains($onPage, 'data-env-mail-form')
    && !str_contains($onPage, $secret) && !str_contains($onPage, 'another-secret') && str_contains($onPage, '••• set'), 'Mail on: the card with «••• задан», the test, «Выключить»; the password nowhere.');
$form = $page($screen, '?edit=mail');
env_assert(str_contains($form, 'data-env-mail-form') && str_contains($form, 'name="password"') && !str_contains($form, 'required aria-describedby="env-mail-password-help"')
    && !str_contains($form, 'another-secret'), '«Изменить» opens the form in place; the password field empty and optional.');

// The spam warning on the card, before any test letter.
$spam = $screen->plan($asking('/admin/server/settings/mail/plan', 'POST', ['service' => 'mailru', 'login' => 'box@corp.ru', 'password' => 'box-secret-Pa55!',
    'from_address' => 'noreply@shop.example', 'from_name' => 'Shop', 'levels' => ['urgent']]));
env_assert($spam instanceof Illuminate\Contracts\View\View, 'A change is planned first.');
$planPage = (string) $spam->render();
env_assert(str_contains($planPage, e($lines['mail']['spam_warning'])) && !str_contains($planPage, 'box-secret') && str_contains($planPage, e($lines['mail']['plan']['password_new']))
    && str_contains($planPage, e($lines['mail']['plan']['after_retest_on'])), 'The plan: the spam warning, the password only as «новый», mail off until the test.');
env_assert(count($caught->letters()) === 0 && $mail->settings()?->service === 'yandex', 'Nothing kept before the confirmation.');
$planId = (string) array_key_last((array) $session->get('larena_env_plan_receipts'));
env_assert(!str_contains(json_encode($session->all(), JSON_THROW_ON_ERROR), 'box-secret'), 'Between the plan and the confirmation the password waits sealed.');
$ran = $screen->run($asking('/admin/server/settings/mail/run', 'POST', ['plan' => $planId]));
env_assert($notice($ran) === $lines['mail']['done']['saved_test'] && $mail->state() === PanelMail::UNCHECKED && $mail->settings()?->service === 'mailru' && $mail->password() === 'box-secret-Pa55!',
    'Confirmed: kept, mail waits for the test letter.');
env_assert($notice($screen->run($asking('/admin/server/settings/mail/run', 'POST', ['plan' => $planId]))) === $lines['mail']['reasons']['plan_expired'], 'A plan is confirmed once.');
// The plan waits in the shared receipt (PlanReceipts): another person's confirmation and one after 15 minutes are refused.
$mailClock = time();
$timedScreen = new PanelMailController($mail, $mailer, $views, $translator, $key, null, static fn (Request $request): string => 'admin@example.ru', receipts: env_plan_receipts($mailClock));
$timedScreen->plan($asking('/admin/server/settings/mail/plan', 'POST', ['action' => 'off']));
$lateId = (string) array_key_last((array) $session->get('larena_env_plan_receipts'));
$mailClock += 901;
env_assert($notice($timedScreen->run($asking('/admin/server/settings/mail/run', 'POST', ['plan' => $lateId]))) === $lines['mail']['reasons']['plan_expired']
    && $mail->state() !== PanelMail::OFF, 'A plan older than 15 minutes is not confirmed.');
$timedScreen->plan($asking('/admin/server/settings/mail/plan', 'POST', ['action' => 'off']));
$otherId = (string) array_key_last((array) $session->get('larena_env_plan_receipts'));
$other = $asking('/admin/server/settings/mail/run', 'POST', ['plan' => $otherId]);
$other->attributes->set(ResolvePanelActor::ATTRIBUTE, new PanelActor('user:admin_identity:2'));
env_assert($notice($timedScreen->run($other)) === $lines['mail']['reasons']['plan_expired'] && $mail->state() !== PanelMail::OFF, 'Another person\'s confirmation is refused.');
$spamCard = $page($screen);
env_assert(str_contains($spamCard, 'data-env-mail-spam') && str_contains($spamCard, e($lines['mail']['spam_warning'])) && str_contains($spamCard, 'data-env-mail-test'), 'The card warns of spam above the test letter.');

$caught->refuse = 'Failed to authenticate on SMTP server with username "box@corp.ru" using the following authenticators: "LOGIN".';
$failed = $screen->test($asking('/admin/server/settings/mail/test', 'POST'));
env_assert($notice($failed) === str_replace(':reason', $lines['mail']['reasons']['auth'], $lines['mail']['test']['failed']) && !str_contains($notice($failed), 'box@corp.ru')
    && $mail->state() === PanelMail::UNCHECKED, 'A refused test: in words, without the server\'s message, mail stays off.');
$caught->refuse = null;
$waited = $screen->test($asking('/admin/server/settings/mail/test', 'POST'));
env_assert($notice($waited) === $lines['mail']['reasons']['test_wait'] && $caught->letters() === [], 'One test letter at a time: the next after 10 seconds.');
$session->forget('larena_env_mail_tested_at');
$sent = $screen->test($asking('/admin/server/settings/mail/test', 'POST'));
env_assert($notice($sent) === str_replace(':to', 'admin@example.ru', $lines['mail']['test']['sent_on']) && $mail->isOn()
    && $caught->letters()[0]->getTo()[0]->getAddress() === 'admin@example.ru', 'The test letter to the person\'s own address switches mail on.');

$offPlan = $screen->plan($asking('/admin/server/settings/mail/plan', 'POST', ['action' => 'off']));
env_assert($offPlan instanceof Illuminate\Contracts\View\View, '«Выключить почту» is planned too.');
$offId = (string) array_key_last((array) $session->get('larena_env_plan_receipts'));
$screen->run($asking('/admin/server/settings/mail/run', 'POST', ['plan' => $offId]));
env_assert($mail->state() === PanelMail::OFF, 'Confirmed: off.');
$nothing = $screen->plan($asking('/admin/server/settings/mail/plan', 'POST', ['service' => 'mailru', 'login' => 'box@corp.ru', 'from_address' => 'noreply@shop.example', 'from_name' => 'Shop', 'levels' => ['urgent']]));
env_assert($nothing instanceof RedirectResponse && $notice($nothing) === $lines['mail']['reasons']['nothing_to_change'], 'The same settings again: nothing to change.');

// 8. Auditor's addition 1: a new password from the server's console, asked from the keyboard; never in «История».
$db->getSchemaBuilder()->create('larena_admin_identities', static function ($table): void {
    $table->increments('id');
    $table->string('email');
    $table->string('display_name')->nullable();
    $table->string('password_hash');
    $table->string('status');
    $table->timestamp('disabled_at')->nullable();
    $table->timestamp('updated_at')->nullable();
});
$db->table('larena_admin_identities')->insert(['email' => 'owner@example.ru', 'password_hash' => password_hash('old-password-Pa55!', PASSWORD_DEFAULT), 'status' => 'active']);
$db->table('larena_admin_identities')->insert(['email' => 'blocked@example.ru', 'password_hash' => 'x', 'status' => 'disabled', 'disabled_at' => '2026-11-18 10:00:00']);
/** @var ArrayObject<int, int> $ended */
$ended = new ArrayObject([]);
$console = new ConsolePasswordReset($db, static function (int $id) use ($ended): void {
    $ended->append($id);
}, $audit);
env_assert($console->reset('owner@example.ru', 'short') === 'too_short' && $console->reset('owner@example.ru', 'onlylettersanddigits1') === 'too_weak', 'The sign-in package\'s password rule.');
env_assert($console->reset('nobody@example.ru', 'new-password-Pa55!') === 'not_found' && $console->reset('blocked@example.ru', 'new-password-Pa55!') === 'blocked', 'Unknown and blocked people are refused.');
env_assert($ended->getArrayCopy() === [] && password_verify('old-password-Pa55!', (string) $db->table('larena_admin_identities')->where('id', 1)->value('password_hash')), 'Nothing changed by a refusal.');
env_assert($console->reset(' Owner@Example.ru ', 'new-password-Pa55!') === 'reset' && $ended->getArrayCopy() === [1]
    && password_verify('new-password-Pa55!', (string) $db->table('larena_admin_identities')->where('id', 1)->value('password_hash')), 'Reset: the new hash, the person\'s sessions ended.');
$console_events = array_values(array_filter($sink->events(), static fn ($event): bool => $event->type === ConsolePasswordReset::AUDIT_TYPE));
env_assert(count($console_events) === 1 && $console_events[0]->actor === 'console' && $console_events[0]->subject === 'user:admin_identity:1'
    && !str_contains(json_encode($console_events[0]->payload, JSON_THROW_ON_ERROR), 'new-password'), '«История»: «пароль сброшен с консоли», never the password.');
$command = (string) file_get_contents(dirname(__DIR__, 2).'/src/Console/PanelResetPasswordCommand.php');
env_assert(str_contains($command, "larena:panel-reset-password {email?") && !str_contains($command, '{--password') && str_contains($command, '->secret(')
    && str_contains($command, "'пароль сброшен с консоли'"), 'The command asks the password from the keyboard, never as an argument, and logs without it.');
$hint = require dirname(__DIR__, 2).'/assembly/lang/vendor/larena-auth/ru/auth.php';
env_assert(str_contains($hint['recovery_without_mail'], 'simai-admin.sh panel reset-password') && str_contains($hint['recovery']['unavailable']['description'], 'simai-admin.sh panel reset-password'),
    'Without mail the sign-in page names the console\'s way for a panel with nobody else to ask.');

// 9. Step 2: the queue of ordinary letters. The scheduler sends them each minute; a failure is tried again after 1, 5
// and 30 minutes, then «не доставлено» and the alert bar; the text is wiped once it is done; a link never waits here.
(require dirname(__DIR__, 2).'/database/migrations/2026_11_19_000008_create_larena_env_mail_letters_table.php')->up();
$letters = new Larena\Env\Mail\MailLetters($db);
$mail->checked('user:admin_identity:1');
env_assert($mail->isOn(), 'Mail on for step 2.');
env_assert($refuses(static fn () => $letters->queue('reset', 'a@example.ru', 'x', 'link'), 'link_not_queued')
    && $refuses(static fn () => $letters->queue('invitation', 'a@example.ru', 'x', 'link'), 'link_not_queued'), 'A letter with a sign-in link is never queued.');
env_assert(Larena\Env\Mail\MailLetters::masked('rim@mail.ru') === 'r***@mail.ru' && Larena\Env\Mail\MailLetters::masked('nobody') === '***', 'The address partly hidden: r***@mail.ru.');
$caught->clear();
$letters->queue('warning', 'Owner@Example.ru', 'Larena Panel: срочно — диск', 'Текст уведомления');
// The queue stamps letters with the real clock: the runs count from just after it, not from a fixed hour.
$t0 = Larena\Env\Mail\MailLetters::now()->modify('+1 second');
env_assert($letters->run($mail, $mailer, $t0) === ['sent' => 1, 'retried' => 0, 'failed' => 0] && count($caught->letters()) === 1
    && $caught->letters()[0]->getTo()[0]->getAddress() === 'owner@example.ru', 'A waiting letter leaves on the next run.');
$row = $db->table(Larena\Env\Mail\MailLetters::TABLE)->orderByDesc('id')->first();
env_assert($row->state === 'sent' && $row->body === null && !$letters->failing(), 'Sent: its text is wiped.');
$letters->queue('warning', 'owner@example.ru', 'Второе', 'Текст');
$caught->refuse = 'Connection could not be established with host "ssl://smtp.mail.ru:465": Connection timed out';
$runs = [];
foreach ([0, 1, 6, 36] as $minute) {
    $runs[] = $letters->run($mail, $mailer, $t0->modify('+'.$minute.' minutes'));
}
env_assert($runs[0]['retried'] === 1 && $runs[1]['retried'] === 1 && $runs[2]['retried'] === 1 && $runs[3]['failed'] === 1, 'Tried four times: at once, after 1, 5 and 30 minutes: '.json_encode($runs));
env_assert($letters->run($mail, $mailer, $t0->modify('+40 minutes')) === ['sent' => 0, 'retried' => 0, 'failed' => 0], 'Given up: not tried again.');
$letters->queue('warning', 'owner@example.ru', 'Третье', 'Текст');
env_assert($letters->run($mail, $mailer, $t0->modify('+40 minutes'))['retried'] === 1
    && $letters->run($mail, $mailer, $t0->modify('+40 minutes 30 seconds')) === ['sent' => 0, 'retried' => 0, 'failed' => 0], 'Not before its minute.');
$givenUp = $db->table(Larena\Env\Mail\MailLetters::TABLE)->where('subject', 'Второе')->first();
env_assert($givenUp->state === 'failed' && $givenUp->reason === 'unreachable' && (int) $givenUp->attempts === 4 && $givenUp->body === null && $letters->failing(),
    '«Не доставлено»: the reason in a word, the text wiped, the bar says «Почта не уходит».');
$caught->refuse = null;

// The alert bar: only for those who may change the server's settings, only while mail is on, with the way to «Почта».
$rights = new class implements Illuminate\Contracts\Config\Repository {
    /** @var array<string, mixed> */
    private array $items = ['larena-access.operations' => [
        'env.server.operate' => ['target' => 'server:host', 'required_grants' => ['operate'], 'test_grants' => ['test:system' => ['host' => ['operate']], 'test:staff' => ['host' => []]]],
    ]];

    public function has($key): bool { return array_key_exists($key, $this->items); }
    public function get($key, $default = null): mixed { return $this->items[$key] ?? $default; }
    public function all(): array { return $this->items; }
    public function set($key, $value = null): void { $this->items[$key] = $value; }
    public function prepend($key, $value): void {}
    public function push($key, $value): void {}
};
$authorizer = new Larena\Access\Runtime\AccessOperationAuthorizer($rights, 'testing');
$who = static function (string $subject): Request {
    $request = Request::create('/admin/server');
    $request->attributes->set('larena_access_actor', $subject);

    return $request;
};
$bar = new Larena\Env\Warnings\MailAlertSource($mail, $letters, $translator, $authorizer);
$said = $bar->alerts($who('test:system'));
env_assert(count($said) === 1 && $said[0]->id === 'env:mail-failing' && $said[0]->text === $lines['mail']['failing'] && $said[0]->action?->href === '/admin/server/larena.env.admin.settings.mail',
    '«Почта не уходит — проверьте настройки», with the way to «Почта».');
env_assert($russian['mail']['failing'] === 'Почта не уходит — проверьте настройки', 'In the plan\'s words.');
env_assert($bar->alerts($who('test:staff')) === [], 'Not for those who may not change the settings.');

// «История» → «Почта»: when, to whom (partly hidden), which, how it ended; never the text. The object «Почта» is the filter.
$history = new Larena\Env\Admin\MailHistorySource($letters, $translator, $authorizer);
$entries = $history->entries($who('test:system'), 50);
$byTitle = [];
foreach ($entries as $entry) {
    $byTitle[$entry->title] = $entry;
}
env_assert(count($entries) === 3 && isset($byTitle['Letter «Второе»'], $byTitle['Letter «Третье»']), 'Every letter is a row: '.json_encode(array_keys($byTitle)));
$lost = $byTitle['Letter «Второе»'];
env_assert($lost->outcome === 'failed' && $lost->objectKey === 'mail' && $lost->object === 'Mail' && str_contains($lost->note, 'o***@example.ru')
    && str_contains($lost->note, 'Not delivered') && !str_contains(json_encode($entries, JSON_THROW_ON_ERROR), 'owner@example.ru')
    && !str_contains(json_encode($entries, JSON_THROW_ON_ERROR), 'Текст'), '«Не доставлено: причина», the address hidden, no text: '.$lost->note);
env_assert($byTitle['Letter «Третье»']->outcome === 'running' && str_contains($byTitle['Letter «Третье»']->note, 'try 2 of 4'), 'A letter that waits says which try comes.');
env_assert($history->entry($who('test:system'), $lost->key)?->title === $lost->title && $history->entries($who('test:staff'), 50) === [],
    'Its card opens; others see no letters.');
env_assert(Larena\Admin\History\AdminHistory::choices(array_combine(array_map(static fn ($entry): string => 'mail.'.$entry->key, $entries), $entries), 'object') === ['mail' => 'Mail'],
    'The history\'s filter has «Почта».');

// A letter that leaves ends «Почта не уходит»; mail switched off gives the waiting ones up without trying, and that is not
// «Почта не уходит».
$letters->run($mail, $mailer, $t0->modify('+42 minutes'));
env_assert(!$letters->failing() && $bar->alerts($who('test:system')) === [], 'The next letter left: the bar is quiet.');
$letters->queue('warning', 'owner@example.ru', 'Пока выключено', 'Текст');
$mail->switchOff('user:admin_identity:1');
$caught->clear();
env_assert($letters->run($mail, $mailer, $t0->modify('+50 minutes'))['failed'] === 1 && $caught->letters() === [] && !$letters->failing(),
    'Mail off: given up as «почта выключена», nothing sent, not «Почта не уходит».');
$mail->checked('user:admin_identity:1');

// The reset letter is recorded after it went, without its text or the link.
$caught->clear();
$recordingPostman = new PanelPasswordResetDelivery($mail, $mailer, $translator,
    static fn (string $challenge, string $secret): string => 'https://panel.example.ru/admin/password/reset/'.$challenge.'?secret='.$secret, $letters);
env_assert($recordingPostman->deliver($reset) && count($caught->letters()) === 1, 'The reset letter leaves at once.');
$resetRow = (array) $db->table(Larena\Env\Mail\MailLetters::TABLE)->where('kind', 'reset')->first();
env_assert($resetRow['state'] === 'sent' && $resetRow['body'] === null && !str_contains(json_encode($resetRow, JSON_THROW_ON_ERROR), 'reset-secret-1'), 'Recorded: sent, no text, no link.');

// 10. The invitation by mail: at once, with who invites, what they will do and the link «Задать пароль и войти»; the link
// lives 72 hours and is never kept; at most 20 an hour, one address again after 5 minutes.
$invitationLetter = new Larena\Env\Mail\InvitationLetter();
$invitationLetter->about('Анна', 'Только свои сайты');
$invitations = new Larena\Env\Mail\PanelInvitationDelivery($mail, $mailer, $translator,
    static fn (string $challenge, string $secret): string => 'https://panel.example.ru/admin/activate/'.$challenge.'?secret='.$secret, $invitationLetter, 'panel.example.ru', $letters);
$caught->clear();
$invite = new Larena\Auth\ValueObjects\ActivationDeliveryMessage('new@example.ru', 'invite_ref', 'activation-secret-1', Carbon::now()->addHours(72));
env_assert($invitations->deliver($invite) && count($caught->letters()) === 1, 'The invitation leaves at once.');
$inviteBody = (string) $caught->letters()[0]->getTextBody();
env_assert(str_contains($inviteBody, 'Анна') && str_contains($inviteBody, 'Только свои сайты') && str_contains($inviteBody, 'panel.example.ru')
    && str_contains($inviteBody, 'https://panel.example.ru/admin/activate/invite_ref?secret=activation-secret-1') && str_contains($inviteBody, '72 h'),
    'Who invites, to which panel, what they will do, the link and its 72 hours.');
$inviteRow = (array) $db->table(Larena\Env\Mail\MailLetters::TABLE)->where('kind', 'invitation')->first();
env_assert($inviteRow['body'] === null && !str_contains(json_encode($inviteRow, JSON_THROW_ON_ERROR), 'activation-secret') && isset($letters->invitedUntil()['new@example.ru']),
    'Recorded without text or link; until when it waits is known for «Люди».');
env_assert($letters->invitationRefusal('New@example.ru') === 'invitation_again' && $letters->invitationRefusal('other@example.ru') === null, 'One address again only after 5 minutes.');
for ($i = 0; $i < 19; $i++) {
    $letters->record('invitation', 'p'.$i.'@example.ru', 'x', null);
}
env_assert($letters->invitationRefusal('other@example.ru') === 'invitations_hour', 'At most 20 invitations an hour.');
$caught->refuse = 'Expected response codes "250/251/252" but got code "550", with message "550 5.1.1 No such user".';
env_assert(!$invitations->deliver($invite), 'A refused letter answers false: the sign-in package withdraws the invitation.');
$caught->refuse = null;
$db->table(Larena\Env\Mail\MailLetters::TABLE)->where('kind', 'invitation')->delete();
env_assert($russian['admin']['people']['invite']['hows']['mail']['label'] === 'Отправить приглашение на почту'
    && $russian['admin']['people']['invite']['hows']['password']['label'] === 'Задать первый пароль самому'
    && !str_contains($russian['admin']['people']['invite']['email_hint'], 'Писем панель не отправляет'), 'The choice in the plan\'s words; the old hint is gone.');

// 11. «Ваш пароль изменён»: after a reset by the link (the sign-in package's event) or from the console, an ordinary letter.
$passing = new class implements Larena\Auth\Contracts\AuthSecurityEventSink {
    /** @var list<string> */
    public array $types = [];
    public function record(Larena\Auth\ValueObjects\AuthSecurityEvent $event): void { $this->types[] = $event->type; }
};
$changed = new Larena\Env\Mail\PasswordChangedLetter($db, $mail, $letters, $translator, 'panel.example.ru');
$securityLetters = new Larena\Env\Mail\PanelSecurityLetters($passing, static fn (): Larena\Env\Mail\PasswordChangedLetter => $changed);
$event = static fn (string $type): Larena\Auth\ValueObjects\AuthSecurityEvent => new Larena\Auth\ValueObjects\AuthSecurityEvent($type, 'anonymous', 'user:admin_identity:1', new DateTimeImmutable());
$securityLetters->record($event('auth.password_recovery.requested'));
env_assert($db->table(Larena\Env\Mail\MailLetters::TABLE)->where('kind', 'password_changed')->count() === 0, 'A request alone changes no password: no letter.');
$securityLetters->record($event('auth.password_recovery.completed'));
$changedRow = $db->table(Larena\Env\Mail\MailLetters::TABLE)->where('kind', 'password_changed')->first();
env_assert($passing->types === ['auth.password_recovery.requested', 'auth.password_recovery.completed'] && $changedRow !== null
    && $changedRow->to_address === 'owner@example.ru' && $changedRow->state === 'queued' && str_contains((string) $changedRow->body, 'panel.example.ru'),
    'The events go on as they were; a completed recovery queues «Ваш пароль изменён» to the person.');
env_assert(!$changed->queueFor('user:admin_identity:99') && !$changed->queueFor('nobody'), 'Nobody to write to: no letter.');
env_assert($russian['mail']['letters']['password_changed']['subject'] === 'Larena Panel: ваш пароль изменён'
    && str_contains($russian['mail']['letters']['password_changed']['body'], 'Если это были не вы'), '«Ваш пароль изменён; если это были не вы — …».');
$command = (string) file_get_contents(dirname(__DIR__, 2).'/src/Console/PanelResetPasswordCommand.php');
env_assert(str_contains($command, 'PasswordChangedLetter::class)->queueFor('), 'The console\'s reset sends the same letter.');

// 12. The server's warnings by mail, as «Что слать на почту» says: urgent only by default; to active people with an address.
$warningLetters = new Larena\Env\Mail\WarningLetters($db, $mail, $letters);
env_assert($mail->settings()?->levels === ['urgent'] && $warningLetters->takes(true) && !$warningLetters->takes(false), 'Urgent only, as chosen.');
env_assert($warningLetters->queue(['user:admin_identity:1', 'user:admin_identity:2', 'user:admin_identity:1', 'test:other'], 'Срочно', 'Текст') === 1,
    'One letter per active person with an address; a blocked one gets none.');
$mail->switchOff('user:admin_identity:1');
env_assert(!$warningLetters->takes(true), 'Mail off: nothing by mail.');
$checkCommand = (string) file_get_contents(dirname(__DIR__, 2).'/src/Console/PanelCheckCommand.php');
env_assert(str_contains($checkCommand, '$letters->takes($urgent)') && str_contains($checkCommand, "'larena-env::mail.letters.warning.subject_'"), 'The panel\'s check queues them.');

echo "PanelMailTest passed.\n";
