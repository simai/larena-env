<?php

declare(strict_types=1);

namespace Larena\Env\Firewall;

use DateTimeImmutable;
use DateTimeZone;
use Exception;

/**
 * A change the server undoes by itself unless it is kept in time (the firewall, who reaches the panel, a site's nginx
 * directives): its id for change:confirm, what kind it is, and when the previous state comes back.
 */
final readonly class TimedChange
{
    public function __construct(
        public string $changeId,
        public ?string $kind,
        public ?string $expiresAt,
        public string $state = 'pending',
    ) {
    }

    /** @param array<array-key, mixed> $data a change as the server names it: change_id, kind, expires_at, state */
    public static function fromData(array $data): ?self
    {
        $id = $data['change_id'] ?? null;
        if (!is_string($id) || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/D', $id) !== 1) {
            return null;
        }

        return new self($id, is_string($data['kind'] ?? null) ? $data['kind'] : null,
            is_string($data['expires_at'] ?? null) ? $data['expires_at'] : null,
            is_string($data['state'] ?? null) ? $data['state'] : 'pending');
    }

    public function pending(): bool
    {
        return $this->state === 'pending';
    }

    /** Seconds left until the server puts the previous state back; null when it did not say when. */
    public function secondsLeft(DateTimeImmutable $now): ?int
    {
        if ($this->expiresAt === null) {
            return null;
        }
        try {
            $until = new DateTimeImmutable($this->expiresAt, new DateTimeZone('UTC'));
        } catch (Exception) {
            return null;
        }

        return max(0, $until->getTimestamp() - $now->getTimestamp());
    }
}
