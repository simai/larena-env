<?php

declare(strict_types=1);

namespace Larena\Env\Server;

/** An SFTP account of the server: its login, whether it is a site's own or the server's, its keys count and whether it is on. */
final readonly class SftpAccount
{
    public function __construct(public string $login, public string $kind, public ?string $site, public int $keys, public bool $enabled)
    {
    }

    /** @param array<array-key, mixed> $data */
    public static function fromData(array $data): ?self
    {
        $login = $data['login'] ?? null;
        if (!is_string($login) || preg_match('/\A[a-z][a-z0-9_-]{0,31}\z/D', $login) !== 1) {
            return null;
        }

        return new self($login, is_string($data['kind'] ?? null) ? $data['kind'] : 'project', is_string($data['site'] ?? null) ? $data['site'] : null,
            is_int($data['keys'] ?? null) ? $data['keys'] : 0, ($data['enabled'] ?? true) === true);
    }
}
