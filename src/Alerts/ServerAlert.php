<?php

declare(strict_types=1);

namespace Larena\Env\Alerts;

/**
 * One problem the server's own watch found (simai-env 1.31.0, `simai-alerts/1`): it checks every 5 minutes, also while
 * the panel is closed or down. The same problem keeps the same id; it is open, acknowledged by a person, or resolved
 * once its condition cleared. Since simai-env 1.63.0 it says itself how urgent it is (`level`: urgent or warning) and
 * what it is in Russian (`title`, with a `line` of advice or detail where one helps); `message` stays English and is
 * never shown. A resolved alert stays the same record for 7 days; when its condition comes back it opens again under the
 * same id with a new first_seen.
 */
final readonly class ServerAlert
{
    public const string OPEN = 'open';
    public const string ACKED = 'acked';
    public const string RESOLVED = 'resolved';

    public const string URGENT = 'urgent';
    public const string WARNING = 'warning';

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
        public string $level = self::WARNING,
        public ?string $title = null,
        public ?string $line = null,
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
        $text = static fn (string $key): ?string => is_string($data[$key] ?? null) && trim($data[$key]) !== '' ? trim($data[$key]) : null;
        $severity = $text('severity') ?? 'warning';

        return new self($id, $kind, $severity, $text('domain'), $text('target'), $text('message') ?? $kind, $state,
            $text('first_seen'), $text('last_seen'), $text('acked_at'), $text('acked_by'), $text('resolved_at'),
            self::levelOf($data['level'] ?? null, $severity), self::clip($text('title'), 200), self::clip($text('line'), 500));
    }

    /**
     * How urgent: the server's own `level` (1.63.0), else its severity as the server maps it — critical is urgent,
     * anything else (warning, a security finding's high) a warning.
     */
    public static function levelOf(mixed $level, string $severity): string
    {
        if ($level === self::URGENT || $level === self::WARNING) {
            return $level;
        }

        return in_array($severity, ['critical', 'error'], true) ? self::URGENT : self::WARNING;
    }

    private static function clip(?string $value, int $length): ?string
    {
        return $value === null ? null : mb_substr($value, 0, $length);
    }
}
