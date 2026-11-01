<?php

declare(strict_types=1);

namespace Larena\Env\Server;

/**
 * An SFTP account of the server (`simai-access/1`, simai-env 1.30.0): its login, whether it is a site's own (`project`)
 * or the server's, the site it reaches, whether it is on, how it signs in (by key only) and where it lands.
 */
final readonly class SftpAccount
{
    public function __construct(
        public string $login,
        public string $type,
        public ?string $site,
        public bool $enabled,
        public string $auth,
        public ?string $path,
        public ?string $createdAt,
    ) {
    }

    /** @param array<array-key, mixed> $data */
    public static function fromData(array $data): ?self
    {
        $login = $data['login'] ?? null;
        if (!is_string($login) || preg_match('/\A[a-z][a-z0-9_-]{0,31}\z/D', $login) !== 1) {
            return null;
        }

        return new self(
            $login,
            is_string($data['type'] ?? null) ? $data['type'] : 'project',
            is_string($data['domain'] ?? null) ? $data['domain'] : null,
            ($data['enabled'] ?? true) === true,
            is_string($data['auth'] ?? null) ? $data['auth'] : 'key',
            is_string($data['sftp_path'] ?? null) ? $data['sftp_path'] : null,
            is_string($data['created_at'] ?? null) ? $data['created_at'] : null,
        );
    }
}
