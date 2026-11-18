<?php

declare(strict_types=1);

namespace Larena\Env\Operations;

/**
 * What a plan's check found (simai-env 1.60.0 `checks`): what would stop the action (the domain points elsewhere, not
 * enough disk, the site exists) and what is worth knowing. In the server's words, never a secret.
 */
final readonly class PlanChecks
{
    private const int MOST = 20;

    /**
     * @param list<string> $blockers
     * @param list<string> $notes
     */
    public function __construct(public array $blockers = [], public array $notes = [])
    {
    }

    public static function fromData(mixed $data): ?self
    {
        if (!is_array($data)) {
            return null;
        }
        $lines = static fn (mixed $list): array => is_array($list) ? array_slice(array_values(array_filter(array_map(
            static fn (mixed $line): ?string => is_string($line) && trim($line) !== '' ? mb_substr(trim($line), 0, 500) : null, $list), 'is_string')), 0, self::MOST) : [];

        return new self($lines($data['blockers'] ?? null), $lines($data['notes'] ?? null));
    }

    /** @return list<array{text: string, blocking: bool}> the checks as «Действие с планом» warnings: blockers first */
    public function warnings(): array
    {
        return [...array_map(static fn (string $line): array => ['text' => $line, 'blocking' => true], $this->blockers),
            ...array_map(static fn (string $line): array => ['text' => $line, 'blocking' => false], $this->notes)];
    }
}
