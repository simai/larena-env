<?php

declare(strict_types=1);

namespace Larena\Env\Security;

/**
 * The files moved out of the sites into quarantine (security:quarantine-list, simai-quarantine/1, simai-env 1.46.0),
 * each with where it came from and whether it is still there or was put back.
 */
final readonly class Quarantine
{
    public const string SCHEMA = 'simai-quarantine/1';

    /** @param list<array{id: string, domain: string, path: string, size: ?int, state: string, at: ?string, restoredAt: ?string}> $files */
    public function __construct(public array $files)
    {
    }

    /** @return list<array{id: string, domain: string, path: string, size: ?int, state: string, at: ?string, restoredAt: ?string}> */
    public function kept(): array
    {
        return array_values(array_filter($this->files, static fn (array $file): bool => $file['state'] === 'quarantined'));
    }

    /** @param array<array-key, mixed> $data */
    public static function fromData(array $data): ?self
    {
        if (($data['schema'] ?? null) !== self::SCHEMA) {
            return null;
        }
        $files = [];
        foreach (is_array($data['files'] ?? null) ? $data['files'] : [] as $file) {
            if (!is_array($file) || !is_string($file['id'] ?? null) || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/D', $file['id']) !== 1
                || !is_string($file['domain'] ?? null) || !is_string($file['path'] ?? null)) {
                continue;
            }
            $files[] = [
                'id' => $file['id'],
                'domain' => strtolower(mb_substr($file['domain'], 0, 253)),
                'path' => mb_substr($file['path'], 0, 1024),
                'size' => is_int($file['size'] ?? null) ? $file['size'] : null,
                'state' => ($file['state'] ?? null) === 'restored' ? 'restored' : 'quarantined',
                'at' => is_string($file['quarantined_at'] ?? null) ? $file['quarantined_at'] : null,
                'restoredAt' => is_string($file['restored_at'] ?? null) ? $file['restored_at'] : null,
            ];
        }

        return new self($files);
    }
}
