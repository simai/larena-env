<?php

declare(strict_types=1);

namespace Larena\Env\Update;

/**
 * What a safe update would change (simai-env 1.32.0, `simai-update-plan/1`), for simai-env itself or for the panel's
 * build: the versions, the release notes between them, the copy taken first, what restarts, which sites are asked
 * before and after, and what comes back if they stop answering. `blocked` says why it cannot run now.
 */
final readonly class UpdatePlan
{
    public const string SCHEMA = 'simai-update-plan/1';
    public const array TARGETS = ['env', 'panel'];
    public const array BLOCKED = ['not_installed', 'placeholder', 'remote_unavailable'];

    /**
     * @param 'env'|'panel' $target
     * @param list<array{version: string, text: string}> $notes
     * @param list<string> $restarts
     * @param list<string> $checks the sites asked before and after; for the panel, none
     */
    public function __construct(
        public string $target,
        public ?string $current,
        public ?string $available,
        public bool $updateAvailable,
        public ?string $blocked,
        public array $notes,
        public ?string $notesUrl,
        public ?string $backup,
        public array $restarts,
        public array $checks,
        public ?string $rollback,
    ) {
    }

    /** @param array<array-key, mixed> $data null when the answer is not an update plan the panel knows */
    public static function fromData(array $data): ?self
    {
        $target = $data['target'] ?? null;
        if (($data['schema'] ?? null) !== self::SCHEMA || !in_array($target, self::TARGETS, true)) {
            return null;
        }
        $text = static fn (string $key): ?string => is_string($data[$key] ?? null) && $data[$key] !== '' ? $data[$key] : null;
        $strings = static fn (string $key): array => array_values(array_filter(is_array($data[$key] ?? null) ? $data[$key] : [],
            static fn (mixed $item): bool => is_string($item) && $item !== ''));
        $notes = [];
        foreach (is_array($data['notes'] ?? null) ? $data['notes'] : [] as $note) {
            if (is_array($note) && is_string($note['version'] ?? null) && is_string($note['text'] ?? null)) {
                $notes[] = ['version' => $note['version'], 'text' => trim($note['text'])];
            }
        }
        // An unknown reason still blocks: the panel never offers an update the server says it cannot run.
        $blocked = $data['blocked'] ?? null;
        $blocked = $blocked === null ? null : (in_array($blocked, self::BLOCKED, true) ? $blocked : 'unknown');
        // The notes' address is a link on the page: https only.
        $url = $text('notes_url');

        return new self(
            target: $target,
            current: $text('current'),
            available: $text('available'),
            updateAvailable: ($data['update_available'] ?? false) === true && $blocked === null,
            blocked: $blocked,
            notes: $notes,
            notesUrl: $url !== null && str_starts_with($url, 'https://') && filter_var($url, FILTER_VALIDATE_URL) !== false ? $url : null,
            backup: $text('backup'),
            restarts: $strings('restarts'),
            checks: $strings('checks'),
            rollback: $text('rollback'),
        );
    }
}
