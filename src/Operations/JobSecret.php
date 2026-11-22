<?php

declare(strict_types=1);

namespace Larena\Env\Operations;

/**
 * The administrator WordPress was installed with (wp:install, simai-env 1.56.0): the login, the generated password and
 * the login page. The server gives the password in one answer only and keeps no copy; the panel shows it on that screen
 * and keeps it nowhere: not in its cache, its session, its logs or its history.
 */
final readonly class JobSecret
{
    public function __construct(public string $user, #[\SensitiveParameter] public string $password, public ?string $loginUrl)
    {
    }

    /** @param array<array-key, mixed> $result */
    public static function fromData(array $result): ?self
    {
        $password = $result['admin_password'] ?? null;
        if (!is_string($password) || $password === '') {
            return null;
        }
        $url = $result['login_url'] ?? null;

        return new self(is_string($result['admin_user'] ?? null) ? $result['admin_user'] : '', $password,
            is_string($url) && preg_match('#\Ahttps?://[^\s<>"]{1,500}\z#', $url) === 1 ? $url : null);
    }

    /**
     * The values the server gives once (its ONCE_RESULT): WordPress's administrator password (1.56.0), a database
     * browser's address (1.58.0), a database password (1.59.0), a site's temporary link with its key (1.65.0).
     */
    public const array ONCE = ['admin_password', 'browser_url', 'db_password', 'preview_url'];

    /** Whether a gateway's answer carries a value shown once: such an answer is never written anywhere. */
    public static function in(mixed $data): bool
    {
        return is_array($data) && is_array($data['result'] ?? null) && array_intersect(self::ONCE, array_keys($data['result'])) !== [];
    }

    /** Never printed by accident (var_dump, a log of the object). @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['user' => $this->user, 'password' => '(shown once)'];
    }
}
