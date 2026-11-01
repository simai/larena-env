<?php

declare(strict_types=1);

namespace Larena\Env\Server;

use UnexpectedValueException;

/**
 * One site as its own screen needs it (simai-site/1): what it runs on, its certificate, its backups and the releases
 * and services an action can act on. Paths, database names, users and hosts of the answer are left out on purpose.
 */
final readonly class SiteDetails
{
    private const string SITE_SCHEMA = 'simai-site/1';

    /**
     * @param list<SiteBackup> $backups
     * @param list<string> $workers
     */
    public function __construct(
        public string $domain,
        public string $profile,
        public string $php,
        public string $ssl,
        public ?string $owner,
        public ?string $databaseEngine,
        public ?SiteCertificate $certificate,
        public ?BackupSchedule $backupSchedule,
        public array $backups,
        public ?string $release,
        public ?string $previousRelease,
        public ?string $deployedAt,
        public ?string $service,
        public array $workers,
        public bool $scheduler,
        // The account the site's code runs as: its own, or its owner's (simai-env's isolation).
        public ?string $runsAs = null,
        // simai-env 1.27.0: what the health path answered (null: nothing, or not checked yet), and the directory's size.
        public ?int $answers = null,
        public bool $answersChecked = false,
        public ?int $diskBytes = null,
        // simai-env 1.30.0: the server's last check of a copy.
        public ?BackupCheck $lastVerify = null,
    ) {
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @throws UnexpectedValueException
     */
    public static function fromDescription(array $data): self
    {
        if (($data['schema'] ?? null) !== self::SITE_SCHEMA) {
            throw new UnexpectedValueException('site_schema_unknown');
        }
        foreach (['domain', 'profile', 'php', 'ssl'] as $required) {
            if (!is_string($data[$required] ?? null) || $data[$required] === '') {
                throw new UnexpectedValueException('site_field_invalid');
            }
        }
        $release = is_array($data['release'] ?? null) ? $data['release'] : [];
        $backups = is_array($data['backups'] ?? null) ? $data['backups'] : [];
        $items = [];
        foreach (is_array($backups['items'] ?? null) ? $backups['items'] : [] as $item) {
            $backup = is_array($item) ? SiteBackup::fromData($item) : null;
            if ($backup !== null) {
                $items[] = $backup;
            }
        }
        $workers = [];
        foreach (is_array($data['workers'] ?? null) ? $data['workers'] : [] as $worker) {
            $name = is_array($worker) ? ($worker['name'] ?? null) : $worker;
            if (is_string($name) && $name !== '') {
                $workers[] = $name;
            }
        }

        return new self(
            domain: $data['domain'],
            profile: $data['profile'],
            php: $data['php'],
            ssl: $data['ssl'],
            owner: is_string($data['owner'] ?? null) ? $data['owner'] : null,
            databaseEngine: is_array($data['database'] ?? null) && is_string($data['database']['engine'] ?? null) ? $data['database']['engine'] : null,
            certificate: is_array($data['certificate'] ?? null) ? SiteCertificate::fromData($data['certificate']) : null,
            backupSchedule: is_array($backups['schedule'] ?? null) ? BackupSchedule::fromData($backups['schedule']) : null,
            backups: $items,
            release: is_string($release['current'] ?? null) ? $release['current'] : null,
            previousRelease: is_string($release['previous'] ?? null) ? $release['previous'] : null,
            deployedAt: is_string($release['deployed_at'] ?? null) ? $release['deployed_at'] : null,
            service: is_string($data['service'] ?? null) && $data['service'] !== '' ? $data['service'] : null,
            workers: $workers,
            scheduler: ($data['scheduler'] ?? false) === true,
            runsAs: is_string($data['runs_as'] ?? null) && $data['runs_as'] !== '' ? $data['runs_as'] : null,
            answers: is_array($data['answers'] ?? null) && is_int($data['answers']['http'] ?? null) ? $data['answers']['http'] : null,
            answersChecked: is_array($data['answers'] ?? null),
            diskBytes: is_array($data['disk'] ?? null) && is_int($data['disk']['bytes'] ?? null) ? $data['disk']['bytes'] : null,
            lastVerify: is_array($data['last_verify'] ?? null) ? BackupCheck::fromData($data['last_verify'])
                : (is_array($backups['last_verify'] ?? null) ? BackupCheck::fromData($backups['last_verify']) : null),
        );
    }
}
