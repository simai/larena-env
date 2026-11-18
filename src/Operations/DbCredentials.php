<?php

declare(strict_types=1);

namespace Larena\Env\Operations;

/**
 * How to connect to a site's database (site:db-credentials, simai-env 1.59.0): the engine, the host and port, the
 * database, the user and its password, for another application or a desktop client over an SSH tunnel. The server gives
 * the password in one answer only (the first job:status after the job ended, ONCE_RESULT) and never in the job's log;
 * the panel shows it on that screen and keeps it nowhere: not in its cache, its session, its database, its logs or its
 * history.
 */
final readonly class DbCredentials
{
    public function __construct(
        public ?string $engine,
        public ?string $host,
        public ?string $port,
        public ?string $database,
        public ?string $user,
        #[\SensitiveParameter] public string $password,
    ) {
    }

    /** @param array<array-key, mixed> $result */
    public static function fromData(array $result): ?self
    {
        $password = $result['db_password'] ?? null;
        if (!is_string($password) || $password === '' || strlen($password) > 512) {
            return null;
        }
        $text = static fn (string $key): ?string => is_string($result[$key] ?? null) && $result[$key] !== '' ? mb_substr($result[$key], 0, 253)
            : (is_int($result[$key] ?? null) ? (string) $result[$key] : null);

        return new self($text('engine'), $text('host'), $text('port'), $text('database'), $text('user'), $password);
    }

    /** Never printed by accident (var_dump, a log of the object). @return array<string, ?string> */
    public function __debugInfo(): array
    {
        return ['engine' => $this->engine, 'host' => $this->host, 'port' => $this->port, 'database' => $this->database, 'user' => $this->user, 'password' => '(shown once)'];
    }
}
