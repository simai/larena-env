<?php

declare(strict_types=1);

namespace Larena\Env\Mail;

use Closure;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransportFactory;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * Sends one letter at once through the panel's mail settings: Laravel's SMTP transport (Symfony Mailer's ESMTP, as
 * Laravel's mail manager builds it) to an external server or service, waiting at most 10 seconds. The answer is a
 * reason in words or null when the server took the letter; the server's own message, which can name the login, is
 * never kept or shown.
 */
final readonly class PanelMailer
{
    public const int TIMEOUT_SECONDS = 10;

    /** @var Closure(MailSettings, string): TransportInterface */
    private Closure $transports;

    /** @param (Closure(MailSettings, string): TransportInterface)|null $transports how a transport is made; tests give their own */
    public function __construct(?Closure $transports = null)
    {
        $this->transports = $transports ?? self::smtp(...);
    }

    /** @return string|null why the letter did not leave (`mail.reasons.<reason>`), null when it left */
    public function send(MailSettings $settings, #[\SensitiveParameter] string $password, string $to, string $subject, string $text): ?string
    {
        try {
            $email = (new Email())
                ->from(new Address($settings->fromAddress, $settings->fromName))
                ->to(new Address($to))
                ->subject($subject)
                ->text($text);
            $sent = ($this->transports)($settings, $password)->send($email);
        } catch (TransportExceptionInterface $failed) {
            return self::reason($failed);
        } catch (Throwable) {
            return 'failed';
        }

        return $sent instanceof SentMessage ? null : 'failed';
    }

    /** What the mail server's refusal means for the person, from its SMTP code and Symfony's wording. */
    public static function reason(Throwable $failed): string
    {
        $message = $failed->getMessage();
        $code = $failed->getCode();
        $said = preg_match('/got code "(\d{3})"/', $message, $found) === 1 ? (int) $found[1] : (is_int($code) ? $code : 0);

        return match (true) {
            str_contains($message, 'Failed to authenticate') || in_array($said, [530, 534, 535], true) => 'auth',
            str_contains($message, 'STARTTLS') || str_contains($message, 'SSL operation failed') || str_contains($message, 'certificate') => 'tls',
            str_contains($message, 'timed out') || str_contains($message, 'Connection could not be established') || str_contains($message, 'Unable to connect') => 'unreachable',
            str_contains($message, 'SSL') || str_contains($message, 'TLS') => 'tls',
            str_contains($message, '250/251/252') && $said >= 500 => 'recipient',
            $said >= 500 && $said < 600 => 'sender',
            default => 'failed',
        };
    }

    /** Laravel's SMTP transport for these settings: SSL on 465, STARTTLS required on others, plain only to this machine. */
    private static function smtp(MailSettings $settings, #[\SensitiveParameter] string $password): TransportInterface
    {
        $options = match ($settings->encryption) {
            'tls' => ['require_tls' => 'true'],
            'none' => ['auto_tls' => 'false'],
            default => [],
        };
        if ($settings->encryption === 'none' && !MailSettings::local($settings->host)) {
            throw new MailFailed('encryption_none');
        }
        $transport = (new EsmtpTransportFactory())->create(new Dsn(
            $settings->encryption === 'ssl' ? 'smtps' : 'smtp',
            $settings->host,
            $settings->login,
            $password,
            $settings->port,
            $options,
        ));
        if (method_exists($transport, 'getStream')) {
            $stream = $transport->getStream();
            if ($stream instanceof SocketStream) {
                $stream->setTimeout(self::TIMEOUT_SECONDS);
            }
        }

        return $transport;
    }
}
