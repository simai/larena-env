<?php

declare(strict_types=1);

namespace Larena\Env\Operations;

use Larena\Env\Gateway\PanelCapabilities;
use Larena\Env\Server\SiteDetails;

/**
 * The actions a site's screen offers, each one a gateway command with the arguments the screen fills in. The codes are
 * the panel's own words in its addresses and forms; the command and its arguments stay the gateway's.
 */
enum SiteOperation: string
{
    case CertificateIssue = 'certificate-issue';
    case CertificateRenew = 'certificate-renew';
    case Backup = 'backup';
    // A restore is two actions with their own words: the database (after the server backs up the current one) or the
    // files, unpacked beside the site for review; the live files are never replaced.
    case BackupRestoreDatabase = 'backup-restore-database';
    case BackupExtractFiles = 'backup-extract-files';
    case BackupVerify = 'backup-verify';
    case BackupSchedule = 'backup-schedule';
    case Rollback = 'rollback';
    case ServiceRestart = 'service-restart';
    case QueueRestart = 'queue-restart';
    case PhpVersion = 'php-version';
    case PhpSetting = 'php-setting';
    case PhpSettingRemove = 'php-setting-remove';
    case DatabaseCreate = 'database-create';
    case DatabasePassword = 'database-password';
    case DatabaseExport = 'database-export';
    case SftpCreate = 'sftp-create';
    case SftpAddKey = 'sftp-add-key';
    case SftpDisable = 'sftp-disable';
    case SftpEnable = 'sftp-enable';
    case SftpRemove = 'sftp-remove';

    public function command(): string
    {
        return match ($this) {
            self::CertificateIssue => 'ssl:letsencrypt',
            self::CertificateRenew => 'ssl:renew',
            self::Backup => 'backup:data',
            self::BackupRestoreDatabase, self::BackupExtractFiles => 'backup:data-restore',
            self::BackupVerify => 'backup:data-verify',
            self::BackupSchedule => 'backup:data-schedule',
            self::Rollback => 'site:deploy-rollback',
            self::ServiceRestart => 'site:service-restart',
            self::QueueRestart => 'queue:restart',
            self::PhpVersion => 'site:set-php',
            self::PhpSetting => 'site:php-ini-set',
            self::PhpSettingRemove => 'site:php-ini-unset',
            self::DatabaseCreate => 'site:db-create',
            self::DatabasePassword => 'site:db-rotate',
            // A database dump to keep is a backup without the files (simai-env 1.28.0).
            self::DatabaseExport => 'backup:data',
            self::SftpCreate => 'access:create-project',
            self::SftpAddKey => 'access:add-key',
            self::SftpDisable => 'access:disable',
            self::SftpEnable => 'access:enable',
            self::SftpRemove => 'access:remove',
        };
    }

    /** The tab of the site's card the action stands on. */
    public function tab(): string
    {
        return match ($this) {
            self::CertificateIssue, self::CertificateRenew => 'domains',
            self::PhpVersion, self::PhpSetting, self::PhpSettingRemove, self::ServiceRestart, self::QueueRestart => 'php',
            self::DatabaseCreate, self::DatabasePassword, self::DatabaseExport => 'database',
            self::Backup, self::BackupRestoreDatabase, self::BackupExtractFiles, self::BackupVerify, self::BackupSchedule => 'backups',
            self::Rollback => 'releases',
            self::SftpCreate, self::SftpAddKey, self::SftpDisable, self::SftpEnable, self::SftpRemove => 'access',
        };
    }

    /**
     * The form fields this action asks for, by name: `email`, `login` and `value` are text, `pubkey` a public key,
     * `php` a version of the server, `backup` a backup of the site, `name` a `php.ini` setting, `time` a time of day,
     * `keep` a number of backups, and `db`, `files`, `enabled` yes or no.
     *
     * @return list<string>
     */
    public function fields(): array
    {
        return match ($this) {
            self::CertificateIssue => ['email', 'redirect', 'hsts', 'staging'],
            self::Backup => ['db', 'files'],
            self::BackupRestoreDatabase, self::BackupExtractFiles => ['backup'],
            self::BackupVerify => ['backup'],
            self::BackupSchedule => ['enabled', 'time', 'keep'],
            self::PhpVersion => ['php'],
            self::PhpSetting => ['name', 'value'],
            self::PhpSettingRemove => ['name'],
            self::SftpCreate => ['login', 'pubkey'],
            self::SftpAddKey => ['login', 'pubkey'],
            self::SftpDisable, self::SftpEnable, self::SftpRemove => ['login'],
            default => [],
        };
    }

    /** An SFTP account's own action names the account, not the site: the gateway checks it belongs to the site's owner. */
    public function namesSite(): bool
    {
        return !in_array($this, [self::SftpAddKey, self::SftpDisable, self::SftpEnable, self::SftpRemove], true);
    }

    /**
     * Whether the site can be asked for this at all, so the screen offers nothing that would only be refused.
     * A site without a certificate has nothing to renew; without a previous release nothing to roll back to;
     * without workers no queue; a site nginx serves without its own service has nothing to restart; a site without
     * PHP has no version or settings; without a database nothing to export; without backups nothing to restore.
     */
    public function suitsSite(SiteDetails $site): bool
    {
        return match ($this) {
            self::CertificateRenew => $site->certificate !== null,
            self::Rollback => $site->previousRelease !== null,
            self::ServiceRestart => $site->service !== null,
            self::QueueRestart => $site->workers !== [],
            self::PhpVersion, self::PhpSetting, self::PhpSettingRemove => $site->php !== 'none',
            // A database is made for a site that runs code and has none yet.
            self::DatabaseCreate => $site->databaseEngine === null && $site->php !== 'none',
            // The server keeps the password of a WordPress or Bitrix site in the site's own config: it refuses to change it.
            self::DatabasePassword => $site->databaseEngine !== null && !in_array($site->profile, ['wordpress', 'bitrix'], true),
            self::DatabaseExport => $site->databaseEngine !== null,
            self::BackupRestoreDatabase => $site->backups !== [] && $site->databaseEngine !== null,
            self::BackupExtractFiles, self::BackupVerify => $site->backups !== [],
            default => true,
        };
    }

    /**
     * The gateway arguments: the site's domain and what the form carries, left as typed. The command table refuses
     * anything the gateway would refuse, so a mistyped address or email is said in words before the request leaves.
     *
     * @param array<string, string> $input
     *
     * @return array<string, string>
     */
    public function arguments(string $domain, array $input): array
    {
        $args = $this->namesSite() ? ['domain' => $domain] : [];
        // What the action itself fixes, whatever the form says.
        $args += match ($this) {
            self::BackupRestoreDatabase => ['db' => 'yes', 'files' => 'no'],
            self::BackupExtractFiles => ['db' => 'no', 'files' => 'yes'],
            self::DatabaseExport => ['db' => 'yes', 'files' => 'no'],
            default => [],
        };
        foreach ($this->fields() as $field) {
            $value = $input[$field] ?? null;
            if (is_string($value) && $value !== '') {
                $args[$field] = $value;
            }
        }

        return $args;
    }

    /**
     * What the site can be asked for on this server: the actions that suit the site and whose command its gateway has.
     *
     * @return list<self>
     */
    public static function offeredFor(SiteDetails $site, ?PanelCapabilities $capabilities = null): array
    {
        $capabilities ??= PanelCapabilities::none();

        return array_values(array_filter(self::cases(),
            static fn (self $operation): bool => $operation->suitsSite($site) && $capabilities->allows($operation->command())));
    }
}
