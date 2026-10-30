<?php

declare(strict_types=1);

namespace Larena\Env\Panel;

/**
 * The one-time link `panel install` prints: `/?setup=<SIMAI_PANEL_SETUP_TOKEN>`. It opens the first-run screen for one
 * browser session. The token stays in .env only; a session keeps its digest, so `panel setup-link` ends older sessions.
 */
final readonly class SetupLink
{
    public const string TOKEN_KEY = 'SIMAI_PANEL_SETUP_TOKEN';
    public const string SESSION_KEY = 'larena.env.setup_link';

    public function __construct(private EnvFile $env)
    {
    }

    public function accepts(string $candidate): bool
    {
        $token = $this->env->value(self::TOKEN_KEY);

        return $token !== null && hash_equals($token, $candidate);
    }

    public function digest(): ?string
    {
        $token = $this->env->value(self::TOKEN_KEY);

        return $token === null ? null : hash('sha256', $token);
    }

    public function sessionIsOpen(mixed $digest): bool
    {
        $current = $this->digest();

        return $current !== null && is_string($digest) && hash_equals($current, $digest);
    }

    public function consume(): bool
    {
        return $this->env->remove(self::TOKEN_KEY);
    }
}
