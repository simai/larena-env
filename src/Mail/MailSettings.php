<?php

declare(strict_types=1);

namespace Larena\Env\Mail;

/**
 * How the panel sends its own mail (owner decision C335): through an external SMTP server or a mail service, never a
 * mail server of its own and never through simai-env. These are the settings without the password, which the store
 * keeps apart, encrypted. A service fills its own server, port and encryption; SendGrid's login is always «apikey».
 * Plain SMTP without encryption is allowed only towards this machine (a local test catcher), never across a network.
 */
final readonly class MailSettings
{
    public const string SMTP = 'smtp';

    /** The services the panel knows: their server, port, encryption and what their login is. */
    public const array SERVICES = [
        'yandex' => ['host' => 'smtp.yandex.ru', 'port' => 465, 'encryption' => 'ssl', 'login' => null],
        'mailru' => ['host' => 'smtp.mail.ru', 'port' => 465, 'encryption' => 'ssl', 'login' => null],
        'unisender' => ['host' => 'smtp.go1.unisender.ru', 'port' => 587, 'encryption' => 'tls', 'login' => null],
        'sendgrid' => ['host' => 'smtp.sendgrid.net', 'port' => 587, 'encryption' => 'tls', 'login' => 'apikey'],
    ];

    public const array ENCRYPTIONS = ['ssl', 'tls', 'none'];

    /** «Что слать»: urgent by default, as with the server's alerts. Password recovery always goes while mail is on. */
    public const array LEVELS = ['urgent', 'warnings', 'reports'];

    public const string DEFAULT_FROM_NAME = 'Larena Panel';

    /** @param list<string> $levels */
    public function __construct(
        public string $service,
        public string $host,
        public int $port,
        public string $encryption,
        public string $login,
        public string $fromAddress,
        public string $fromName,
        public array $levels,
    ) {
    }

    /**
     * The settings from the form, or why not: the field that is wrong. A service's server, port and encryption are its
     * own whatever the form says.
     *
     * @param array<string, mixed> $input
     */
    public static function fromForm(array $input): self|string
    {
        $service = is_string($input['service'] ?? null) ? $input['service'] : self::SMTP;
        if ($service !== self::SMTP && !isset(self::SERVICES[$service])) {
            return 'service';
        }
        $preset = self::SERVICES[$service] ?? null;
        $host = $preset['host'] ?? strtolower(trim((string) ($input['host'] ?? '')));
        $port = $preset['port'] ?? (int) trim((string) ($input['port'] ?? ''));
        $encryption = $preset['encryption'] ?? (string) ($input['encryption'] ?? '');
        $login = $preset['login'] ?? trim((string) ($input['login'] ?? ''));
        $fromAddress = strtolower(trim((string) ($input['from_address'] ?? '')));
        $fromName = trim((string) ($input['from_name'] ?? ''));
        $levels = array_values(array_intersect(self::LEVELS, array_map('strval', (array) ($input['levels'] ?? []))));

        return match (true) {
            !self::hostValid($host) => 'host',
            $port < 1 || $port > 65535 => 'port',
            !in_array($encryption, self::ENCRYPTIONS, true) => 'encryption',
            $encryption === 'none' && !self::local($host) => 'encryption_none',
            $login === '' || mb_strlen($login) > 254 || preg_match('/[\x00-\x1f\x7f]/', $login) === 1 => 'login',
            filter_var($fromAddress, FILTER_VALIDATE_EMAIL) === false => 'from_address',
            mb_strlen($fromName) > 120 || preg_match('/[\x00-\x1f\x7f<>"]/', $fromName) === 1 => 'from_name',
            default => new self($service, $host, $port, $encryption, $login, $fromAddress, $fromName === '' ? self::DEFAULT_FROM_NAME : $fromName, $levels),
        };
    }

    /**
     * Whether a test letter sent with the other settings still vouches for these: everything that decides whether a
     * letter leaves and how it is signed is the same. «Что слать» is not part of it, so changing it needs no new test.
     */
    public function sameDelivery(self $other): bool
    {
        $delivery = static fn (self $settings): array => array_diff_key($settings->toRow(), ['levels' => true]);

        return $delivery($this) === $delivery($other);
    }

    /**
     * «Письма могут попасть в спам»: the sender's domain is not the mailbox's. Said only where the login is a mailbox
     * address (SMTP, Яндекс 360, Mail.ru); a sending service (SendGrid, Unisender Go) checks the sender's domain itself.
     */
    public function senderMismatch(): bool
    {
        if (filter_var($this->login, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }

        return self::domain($this->login) !== self::domain($this->fromAddress);
    }

    public function isService(): bool
    {
        return $this->service !== self::SMTP;
    }

    /** @return array<string, string|int> what the store keeps, without the password */
    public function toRow(): array
    {
        return [
            'service' => $this->service,
            'host' => $this->host,
            'port' => $this->port,
            'encryption' => $this->encryption,
            'login' => $this->login,
            'from_address' => $this->fromAddress,
            'from_name' => $this->fromName,
            'levels' => implode(',', $this->levels),
        ];
    }

    public static function fromRow(object $row): self
    {
        $levels = array_values(array_intersect(self::LEVELS, explode(',', (string) ($row->levels ?? ''))));

        return new self((string) $row->service, (string) $row->host, (int) $row->port, (string) $row->encryption, (string) $row->login,
            (string) $row->from_address, (string) $row->from_name, $levels);
    }

    /** A name of this machine: the only place plain SMTP may go (a local test catcher). */
    public static function local(string $host): bool
    {
        return in_array(strtolower($host), ['localhost', '127.0.0.1', '::1', '[::1]'], true);
    }

    private static function hostValid(string $host): bool
    {
        return self::local($host) || filter_var($host, FILTER_VALIDATE_IP) !== false
            || (strlen($host) <= 253 && preg_match('/\A[a-z0-9]([a-z0-9-]{0,62}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,62}[a-z0-9])?)+\z/D', $host) === 1);
    }

    private static function domain(string $address): string
    {
        $at = strrpos($address, '@');

        return $at === false ? '' : strtolower(substr($address, $at + 1));
    }
}
