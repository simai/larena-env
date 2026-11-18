<?php

declare(strict_types=1);

namespace Larena\Env\Software;

/**
 * The server's PHP (php:list, simai-php/1, simai-env 1.37.0): the versions it has, the system's own version, and the
 * versions it could install next to them.
 */
final readonly class PhpVersions
{
    /**
     * @param list<PhpVersion> $versions
     * @param list<string> $installable
     */
    public function __construct(
        public ?string $native,
        public array $versions,
        public array $installable,
        // Why nothing older can be installed, where the server says it (`installable_reason`, `installable_words`); an
        // older server says nothing, see sourceMissing().
        public ?string $installableReason = null,
        public ?string $installableWords = null,
    ) {
    }

    /**
     * Whether older versions are out of reach because their source (ondrej/php) is not connected: the server's reason
     * where it gives one; else nothing to install while the server has only its system's own PHP — the Ubuntu repository
     * holds that one alone, so «Все версии уже установлены» would be untrue.
     */
    public function sourceMissing(): bool
    {
        if ($this->installableReason !== null) {
            return in_array($this->installableReason, ['no_source', 'source_missing', 'repository_missing', 'source_not_connected'], true);
        }
        if ($this->installable !== []) {
            return false;
        }
        foreach ($this->versions as $version) {
            if (!$version->native) {
                return false;
            }
        }

        return true;
    }

    public function has(string $version): ?PhpVersion
    {
        foreach ($this->versions as $item) {
            if ($item->version === $version) {
                return $item;
            }
        }

        return null;
    }

    /** @param array<array-key, mixed> $data */
    public static function fromData(array $data): self
    {
        $versions = [];
        foreach (is_array($data['versions'] ?? null) ? $data['versions'] : [] as $item) {
            $made = is_array($item) ? PhpVersion::fromData($item) : null;
            if ($made !== null) {
                $versions[] = $made;
            }
        }
        $installable = is_array($data['installable'] ?? null)
            ? array_values(array_filter($data['installable'], static fn (mixed $version): bool => is_string($version) && preg_match('/\A[5-9]\.[0-9]\z/D', $version) === 1))
            : [];

        $reason = is_string($data['installable_reason'] ?? null) && preg_match('/\A[a-z_]{1,64}\z/D', $data['installable_reason']) === 1 ? $data['installable_reason'] : null;
        $words = is_string($data['installable_words'] ?? null) && trim($data['installable_words']) !== '' ? mb_substr(trim($data['installable_words']), 0, 300) : null;

        return new self(is_string($data['native'] ?? null) ? $data['native'] : null, $versions, $installable, $reason, $words);
    }
}
