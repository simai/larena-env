<?php

declare(strict_types=1);

namespace Larena\Env\Backup;

use Larena\Env\Server\SiteBackup;

/**
 * One backup the server holds (simai-backup-list/1, simai-env 1.64.0): whose it is, whether that site is still on the
 * server, and the backup itself with why it was made and until when it is kept. A removed site's last copy is one too.
 */
final readonly class HeldBackup
{
    /** The reasons the panel says in words (storages.held.reasons): the last copies before a site or its database went. */
    public const array REASONS = ['site-remove', 'db-drop'];

    public const string DOMAIN = '/\A(?=.{1,253}\z)[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+\z/D';

    public function __construct(public string $domain, public bool $siteExists, public SiteBackup $backup)
    {
    }

    /** @param array<array-key, mixed> $data */
    public static function fromData(array $data): ?self
    {
        $domain = is_string($data['domain'] ?? null) ? strtolower($data['domain']) : '';
        $backup = SiteBackup::fromData($data);
        if (preg_match(self::DOMAIN, $domain) !== 1 || $backup === null) {
            return null;
        }

        return new self($domain, ($data['site_exists'] ?? true) !== false, $backup);
    }

    /**
     * The way back this backup offers: a removed site's last copy brings the site back, a dropped database's brings the
     * database back; a scheduled or manual copy is restored from the site's own backups tab.
     *
     * @return list<string>
     */
    public function ways(): array
    {
        return match ($this->backup->reason) {
            'site-remove' => ['return-site'],
            'db-drop' => $this->holdsDatabase() ? ['return-db'] : [],
            default => [],
        };
    }

    /** Whether the database part is in the backup, so «Вернуть базу» has something to put back. */
    public function holdsDatabase(): bool
    {
        return in_array('db', $this->backup->parts, true);
    }
}
