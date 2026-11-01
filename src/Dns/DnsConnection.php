<?php

declare(strict_types=1);

namespace Larena\Env\Dns;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Database\ConnectionInterface;

/**
 * The panel's one connection to a DNS provider. The token is kept encrypted with the application's key; once saved
 * it is never shown again, only its last four characters. The server's own addresses, which the gateway does not
 * describe, are kept beside it: the site card compares a domain's records with them.
 */
final readonly class DnsConnection
{
    public const string TABLE = 'larena_env_dns_connection';

    public function __construct(private ConnectionInterface $connection, private StringEncrypter $encrypter)
    {
    }

    public function current(): ?DnsConnected
    {
        $row = $this->connection->table(self::TABLE)->where('id', 1)->first();

        return $row === null ? null : new DnsConnected(
            (string) $row->provider,
            (string) $row->token_tail,
            (string) $row->token_status,
            $row->token_expires_on === null ? null : (string) $row->token_expires_on,
            $row->server_ipv4 === null ? null : (string) $row->server_ipv4,
            $row->server_ipv6 === null ? null : (string) $row->server_ipv6,
            (string) $row->verified_at,
        );
    }

    /** The token to call the provider with; a token the application's key no longer opens is as good as none. */
    public function token(): ?string
    {
        $row = $this->connection->table(self::TABLE)->where('id', 1)->first();
        if ($row === null) {
            return null;
        }
        try {
            $token = $this->encrypter->decryptString((string) $row->token_encrypted);
        } catch (DecryptException) {
            return null;
        }

        return $token === '' ? null : $token;
    }

    public function connect(string $provider, string $token, DnsTokenStatus $status, string $actor): void
    {
        $current = $this->current();
        $this->connection->table(self::TABLE)->updateOrInsert(['id' => 1], [
            'provider' => $provider,
            'token_encrypted' => $this->encrypter->encryptString($token),
            'token_tail' => substr($token, -4),
            'token_status' => $status->status,
            'token_expires_on' => $status->expiresOn,
            'server_ipv4' => $current?->serverIpv4,
            'server_ipv6' => $current?->serverIpv6,
            'connected_by' => $actor,
            'verified_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
        ]);
    }

    /** The server's own addresses, as the person of the whole server gives them; empty clears. */
    public function setServerAddresses(?string $ipv4, ?string $ipv6): void
    {
        if ($ipv4 !== null && filter_var($ipv4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new DnsFailed('address_invalid');
        }
        if ($ipv6 !== null && filter_var($ipv6, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            throw new DnsFailed('address_invalid');
        }
        if ($this->current() === null) {
            throw new DnsFailed('not_connected');
        }
        $this->connection->table(self::TABLE)->where('id', 1)->update(['server_ipv4' => $ipv4, 'server_ipv6' => $ipv6]);
    }

    public function disconnect(): void
    {
        $this->connection->table(self::TABLE)->where('id', 1)->delete();
    }
}
