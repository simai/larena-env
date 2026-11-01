<?php

declare(strict_types=1);

namespace Larena\Env\Warnings;

/**
 * One thing the panel says out loud before anyone asks (Panel plan 4.4): what it is, how bad, which site or disk, and
 * the values its words need. The words themselves are the panel's lang files (`larena-env::admin.warnings.<kind>`).
 */
final readonly class PanelWarning
{
    public const string WARNING = 'warning';
    public const string ERROR = 'error';

    /** @param array<string, string> $replace */
    public function __construct(
        public string $kind,
        public string $severity,
        public ?string $domain,
        public array $replace = [],
        public ?string $subject = null,
    ) {
    }

    /** The same problem of the same site (or disk) is one warning, however often it is seen. */
    public function key(): string
    {
        return $this->kind.':'.($this->domain ?? $this->subject ?? 'server');
    }

    /** The tab of the site's card where the problem is dealt with. */
    public function tab(): string
    {
        return match ($this->kind) {
            PanelWarnings::CERTIFICATE_ENDING, PanelWarnings::CERTIFICATE_EXPIRED, PanelWarnings::CERTIFICATE_MISSING => 'domains',
            PanelWarnings::BACKUP_NONE, PanelWarnings::BACKUP_STALE, PanelWarnings::BACKUP_UNSCHEDULED => 'backups',
            default => 'overview',
        };
    }
}
