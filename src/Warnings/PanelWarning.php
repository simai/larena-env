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

    /**
     * @param array<string, string> $replace
     * @param ?string $alertId the server's alert this warning is (simai-env 1.31.0); null for one the panel worked out
     * @param ?string $state the alert's state: open, acked or resolved
     */
    public function __construct(
        public string $kind,
        public string $severity,
        public ?string $domain,
        public array $replace = [],
        public ?string $subject = null,
        public ?string $alertId = null,
        public ?string $state = null,
        public ?string $ackedBy = null,
        public ?string $ackedAt = null,
    ) {
    }

    /**
     * The same problem of the same site (or disk) is one warning, however often it is seen. A server's alert is its id
     * and state: it is told once when it opens and once when it is resolved.
     */
    public function key(): string
    {
        return $this->alertId !== null
            ? 'alert:'.$this->alertId.':'.($this->state ?? 'open')
            : $this->kind.':'.($this->domain ?? $this->subject ?? 'server');
    }

    /** An open alert of the server, which a person may acknowledge. */
    public function acknowledgeable(): bool
    {
        return $this->alertId !== null && $this->state === 'open';
    }

    /** The tab of the site's card where the problem is dealt with. */
    public function tab(): string
    {
        return match ($this->kind) {
            PanelWarnings::CERTIFICATE_ENDING, PanelWarnings::CERTIFICATE_EXPIRED, PanelWarnings::CERTIFICATE_MISSING,
            PanelWarnings::CERTIFICATE_RENEW_FAILED, PanelWarnings::CERTIFICATE_ALERT => 'domains',
            PanelWarnings::BACKUP_NONE, PanelWarnings::BACKUP_STALE, PanelWarnings::BACKUP_UNSCHEDULED, PanelWarnings::BACKUP_CHECK_FAILED,
            PanelWarnings::BACKUP_MISSING, PanelWarnings::BACKUP_FAILED => 'backups',
            default => 'overview',
        };
    }
}
