<?php

declare(strict_types=1);

namespace Larena\Env\Backup;

/**
 * A storage for copies of the sites' backups away from the server (backup:storage-list, simai-backup-storages/1,
 * simai-env 1.44.0): an S3 bucket with its endpoint, region and folder, how many days copies are kept there, whether new
 * backups go to it, the access key's hint (never the keys) and how its last test went.
 */
final readonly class BackupStorage
{
    /**
     * The S3 services the add form offers, with their endpoint and region; «Другой» takes them typed.
     *
     * @var array<string, array{endpoint: ?string, region: ?string}>
     */
    public const array PROVIDERS = [
        'yandex' => ['endpoint' => 'https://storage.yandexcloud.net', 'region' => 'ru-central1'],
        'selectel' => ['endpoint' => 'https://s3.ru-1.storage.selcloud.ru', 'region' => 'ru-1'],
        'vk' => ['endpoint' => 'https://hb.ru-msk.vkcloud-storage.ru', 'region' => 'ru-msk'],
        'aws' => ['endpoint' => null, 'region' => 'eu-central-1'],
        'r2' => ['endpoint' => null, 'region' => 'auto'],
        'b2' => ['endpoint' => null, 'region' => null],
        'minio' => ['endpoint' => null, 'region' => null],
        'other' => ['endpoint' => null, 'region' => null],
    ];

    /** @param array{at: ?string, ok: bool, error: ?string}|null $lastTest */
    public function __construct(
        public string $name,
        public ?string $type,
        public ?string $bucket,
        public ?string $endpoint,
        public ?string $region,
        public ?string $prefix,
        public ?int $keep,
        public bool $default,
        public ?string $keyHint,
        public ?array $lastTest,
    ) {
    }

    /** @param array<array-key, mixed> $data */
    public static function fromData(array $data): ?self
    {
        $name = $data['name'] ?? null;
        if (!is_string($name) || preg_match('/\A[a-z0-9][a-z0-9-]{0,31}\z/D', $name) !== 1) {
            return null;
        }
        $text = static fn (string $key): ?string => is_string($data[$key] ?? null) && $data[$key] !== '' ? $data[$key] : null;
        $test = is_array($data['last_test'] ?? null) ? $data['last_test'] : null;

        return new self(
            name: $name,
            type: $text('type'),
            bucket: $text('bucket'),
            endpoint: $text('endpoint'),
            region: $text('region'),
            prefix: $text('prefix'),
            keep: is_int($data['keep'] ?? null) ? $data['keep'] : null,
            default: ($data['default'] ?? false) === true,
            keyHint: $text('access_key_hint'),
            lastTest: $test === null ? null : [
                'at' => is_string($test['at'] ?? null) ? $test['at'] : null,
                'ok' => ($test['ok'] ?? false) === true,
                'error' => is_string($test['error'] ?? null) && $test['error'] !== '' ? $test['error'] : null,
            ],
        );
    }

    /**
     * The copies kept in a storage (backup:storage-copies, simai-backup-copies/1), newest first.
     *
     * @param array<array-key, mixed> $data
     * @return list<array{domain: string, backup: string, created_at: ?string}>
     */
    public static function copies(array $data): array
    {
        $copies = [];
        foreach (is_array($data['copies'] ?? null) ? $data['copies'] : [] as $copy) {
            if (!is_array($copy) || !is_string($copy['domain'] ?? null) || !is_string($copy['backup'] ?? null)
                || preg_match('/\A[a-z0-9.-]{1,253}\z/D', $copy['domain']) !== 1 || preg_match('/\A[0-9]{8}-[0-9]{6}\z/D', $copy['backup']) !== 1) {
                continue;
            }
            $copies[] = ['domain' => $copy['domain'], 'backup' => $copy['backup'], 'created_at' => is_string($copy['created_at'] ?? null) ? $copy['created_at'] : null];
        }
        usort($copies, static fn (array $a, array $b): int => strcmp($b['backup'], $a['backup']));

        return $copies;
    }
}
