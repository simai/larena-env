<?php

declare(strict_types=1);

namespace Larena\Env\Alerts;

/**
 * One problem the server's own watch found (simai-env 1.31.0, `simai-alerts/1`): it checks every 5 minutes, also while
 * the panel is closed or down. The same problem keeps the same id; it is open, acknowledged by a person, or resolved
 * once its condition cleared.
 */
final readonly class ServerAlert
{
    public const string OPEN = 'open';
    public const string ACKED = 'acked';
    public const string RESOLVED = 'resolved';

    public function __construct(
        public string $id,
        public string $kind,
        public string $severity,
        public ?string $domain,
        public ?string $target,
        public string $message,
        public string $state,
        public ?string $firstSeen,
        public ?string $lastSeen,
        public ?string $ackedAt,
        public ?string $ackedBy,
        public ?string $resolvedAt,
    ) {
    }

    /** @param array<array-key, mixed> $data an alert the server answered; null when it is not one */
    public static function fromData(array $data): ?self
    {
        $id = $data['id'] ?? null;
        $kind = $data['kind'] ?? null;
        $state = $data['state'] ?? null;
        if (!is_string($id) || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/D', $id) !== 1 || !is_string($kind) || $kind === ''
            || !in_array($state, [self::OPEN, self::ACKED, self::RESOLVED], true)) {
            return null;
        }
        $text = static fn (string $key): ?string => is_string($data[$key] ?? null) && $data[$key] !== '' ? $data[$key] : null;

        return new self($id, $kind, $text('severity') ?? 'warning', $text('domain'), $text('target'), $text('message') ?? $kind, $state,
            $text('first_seen'), $text('last_seen'), $text('acked_at'), $text('acked_by'), $text('resolved_at'));
    }
}
