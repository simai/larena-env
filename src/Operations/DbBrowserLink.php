<?php

declare(strict_types=1);

namespace Larena\Env\Operations;

/**
 * The address of a site's database browser (site:db-browser, simai-env 1.58.0): Adminer on the site's own HTTPS address,
 * signed in to its database only, until expires_at. The server gives the address in one answer only (the first job:status
 * after the job ended, ONCE_RESULT) and never in the job's log; the panel shows it on that screen and keeps it nowhere: not
 * in its cache, its session, its logs or its history. It opens in a new tab with no referrer.
 */
final readonly class DbBrowserLink
{
    public function __construct(#[\SensitiveParameter] public string $url, public ?string $expiresAt, public ?string $engine, public ?string $database)
    {
    }

    /** @param array<array-key, mixed> $result */
    public static function fromData(array $result): ?self
    {
        $url = $result['browser_url'] ?? null;
        // https://<domain>/.simai-db/<token>/ and nothing else: another address is never shown as the site's browser.
        if (!is_string($url) || preg_match('#\Ahttps://[A-Za-z0-9.-]{1,253}(?::[0-9]{1,5})?/\.simai-db/[A-Za-z0-9_-]{8,128}/\z#D', $url) !== 1) {
            return null;
        }
        $text = static fn (string $key): ?string => is_string($result[$key] ?? null) && $result[$key] !== '' ? $result[$key] : null;

        return new self($url, $text('expires_at'), $text('engine'), $text('database'));
    }

    /** Never printed by accident (var_dump, a log of the object). @return array<string, ?string> */
    public function __debugInfo(): array
    {
        return ['url' => '(shown once)', 'expiresAt' => $this->expiresAt, 'engine' => $this->engine, 'database' => $this->database];
    }
}
