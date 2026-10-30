<?php

declare(strict_types=1);

namespace Larena\Env\Operations;

use Larena\Env\Server\SiteDetails;

/**
 * The actions a site's screen offers, each one a gateway command with the arguments the screen fills in. The codes are
 * the panel's own words in its addresses and forms; the command and its arguments stay the gateway's.
 */
enum SiteOperation: string
{
    case CertificateIssue = 'certificate-issue';
    case CertificateRenew = 'certificate-renew';
    case Backup = 'backup';
    case Rollback = 'rollback';
    case ServiceRestart = 'service-restart';
    case QueueRestart = 'queue-restart';

    public function command(): string
    {
        return match ($this) {
            self::CertificateIssue => 'ssl:letsencrypt',
            self::CertificateRenew => 'ssl:renew',
            self::Backup => 'backup:data',
            self::Rollback => 'site:deploy-rollback',
            self::ServiceRestart => 'site:service-restart',
            self::QueueRestart => 'queue:restart',
        };
    }

    /** The form fields this action asks for, by name: `email` is text, `db` and `files` are yes or no. */
    public function fields(): array
    {
        return match ($this) {
            self::CertificateIssue => ['email', 'redirect', 'hsts', 'staging'],
            self::Backup => ['db', 'files'],
            default => [],
        };
    }

    /**
     * Whether the site can be asked for this at all, so the screen offers nothing that would only be refused.
     * A site without a certificate has nothing to renew; without a previous release nothing to roll back to;
     * without workers no queue; a site nginx serves without its own service has nothing to restart.
     */
    public function suitsSite(SiteDetails $site): bool
    {
        return match ($this) {
            self::CertificateIssue => true,
            self::CertificateRenew => $site->certificate !== null,
            self::Backup => true,
            self::Rollback => $site->previousRelease !== null,
            self::ServiceRestart => $site->service !== null,
            self::QueueRestart => $site->workers !== [],
        };
    }

    /**
     * The gateway arguments: the site's domain and what the form carries, left as typed. The command table refuses
     * anything the gateway would refuse, so a mistyped address or email is said in words before the request leaves.
     *
     * @param array<string, string> $input
     *
     * @return array<string, string>
     */
    public function arguments(string $domain, array $input): array
    {
        $args = ['domain' => $domain];
        foreach ($this->fields() as $field) {
            $value = $input[$field] ?? null;
            if (is_string($value) && $value !== '') {
                $args[$field] = $value;
            }
        }

        return $args;
    }

    /** @return list<self> */
    public static function offeredFor(SiteDetails $site): array
    {
        return array_values(array_filter(self::cases(), static fn (self $operation): bool => $operation->suitsSite($site)));
    }
}
