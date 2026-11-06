<?php

declare(strict_types=1);

namespace Larena\Env\Security;

/**
 * The last check of the sites' files for malicious code (security:files, simai-file-scan/1, simai-env 1.46.0): when it
 * ran, with which engines, and per site the suspicious files it found. The server runs it every night by itself.
 */
final readonly class FileScan
{
    public const string SCHEMA = 'simai-file-scan/1';

    /**
     * @param list<string> $engines
     * @param array<string, array{scanned: ?int, error: ?string, found: list<array{path: string, signature: string, engine: string, size: ?int, modified: ?string}>}> $sites
     */
    public function __construct(
        public ?string $checkedAt,
        public array $engines,
        public array $sites,
    ) {
    }

    public function foundCount(): int
    {
        return array_sum(array_map(static fn (array $site): int => count($site['found']), $this->sites));
    }

    /** @param array<array-key, mixed> $data */
    public static function fromData(array $data): ?self
    {
        if (($data['schema'] ?? null) !== self::SCHEMA) {
            return null;
        }
        $engines = array_values(array_filter(is_array($data['engines'] ?? null) ? $data['engines'] : [],
            static fn (mixed $engine): bool => is_string($engine) && preg_match('/\A[a-z0-9_-]{2,32}\z/D', $engine) === 1));
        $sites = [];
        foreach (is_array($data['sites'] ?? null) ? $data['sites'] : [] as $domain => $site) {
            if (!is_string($domain) || preg_match('/\A[A-Za-z0-9.-]{1,253}\z/D', $domain) !== 1 || !is_array($site)) {
                continue;
            }
            $found = [];
            foreach (is_array($site['found'] ?? null) ? $site['found'] : [] as $file) {
                if (!is_array($file) || !is_string($file['path'] ?? null) || $file['path'] === '') {
                    continue;
                }
                $found[] = [
                    'path' => mb_substr($file['path'], 0, 1024),
                    'signature' => is_string($file['signature'] ?? null) ? mb_substr($file['signature'], 0, 200) : '',
                    'engine' => is_string($file['engine'] ?? null) ? mb_substr($file['engine'], 0, 32) : '',
                    'size' => is_int($file['size'] ?? null) ? $file['size'] : null,
                    'modified' => is_string($file['modified'] ?? null) ? $file['modified'] : null,
                ];
            }
            $sites[strtolower($domain)] = [
                'scanned' => is_int($site['scanned'] ?? null) ? $site['scanned'] : null,
                'error' => is_string($site['error'] ?? null) && $site['error'] !== '' ? mb_substr($site['error'], 0, 300) : null,
                'found' => $found,
            ];
        }

        return new self(is_string($data['checked_at'] ?? null) ? $data['checked_at'] : null, $engines, $sites);
    }
}
