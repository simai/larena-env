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

    /** Whether a gateway's answer carries a value shown once: such an answer is never written anywhere. */
    public static function in(mixed $data): bool
    {
        return is_array($data) && is_array($data['result'] ?? null) && array_key_exists('admin_password', $data['result']);
    }

    /** Never printed by accident (var_dump, a log of the object). @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['user' => $this->user, 'password' => '(shown once)'];
    }
}
