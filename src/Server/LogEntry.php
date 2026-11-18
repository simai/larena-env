<?php

declare(strict_types=1);

namespace Larena\Env\Server;

/**
 * One line of a server log as the server reads it (simai-env 1.60.0 `format: json`, its lib/log_entries.py): the moment
 * in ISO 8601 with its zone, the level (nginx's for its error log, journald's priority for a service, and for a request
 * the answer's: error for 5xx, warn for 4xx), the request's answer, method, path and address, the message, and the line
 * as written. The panel no longer reads the lines itself: no guessing of a level from words, no zone assumed.
 */
final readonly class LogEntry
{
    /** The levels a person sees as an error or a warning: nginx's and journald's names. */
    public const array ERRORS = ['emerg', 'alert', 'crit', 'err', 'error'];
    public const array WARNINGS = ['warn', 'warning'];

    public function __construct(
        public string $raw,
        public ?string $at = null,
        public ?string $level = null,
        public ?int $status = null,
        public ?string $method = null,
        public ?string $path = null,
        public ?string $ip = null,
        public ?string $message = null,
        public ?string $unit = null,
    ) {
    }

    /** @return list<self>|null the entries of an answer, or null when it has none (an older server, or a text answer) */
    public static function listOf(mixed $entries): ?array
    {
        if (!is_array($entries) || !array_is_list($entries)) {
            return null;
        }
        $kept = [];
        foreach ($entries as $entry) {
            if (!is_array($entry) || !is_string($entry['raw'] ?? null)) {
                continue;
            }
            $kept[] = new self(
                raw: $entry['raw'],
                at: self::moment($entry['at'] ?? null),
                level: is_string($entry['level'] ?? null) && preg_match('/\A[a-z]{1,16}\z/D', $entry['level']) === 1 ? $entry['level'] : null,
                status: is_int($entry['status'] ?? null) && $entry['status'] >= 100 && $entry['status'] <= 599 ? $entry['status'] : null,
                method: self::word($entry['method'] ?? null, 16),
                path: self::word($entry['path'] ?? null, 2000),
                ip: self::word($entry['ip'] ?? $entry['client'] ?? null, 64),
                message: self::word($entry['message'] ?? null, 2000),
                unit: self::word($entry['unit'] ?? null, 128),
            );
        }

        return $kept;
    }

    /** error, warning or null: how the line stands, from the server's level alone. */
    public function tone(): ?string
    {
        return match (true) {
            in_array($this->level, self::ERRORS, true) => 'error',
            in_array($this->level, self::WARNINGS, true) => 'warning',
            default => null,
        };
    }

    /** A moment with its zone, as the server gives it; anything else is not a moment. */
    private static function moment(mixed $value): ?string
    {
        return is_string($value) && preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})\z/D', $value) === 1 ? $value : null;
    }

    private static function word(mixed $value, int $max): ?string
    {
        return is_string($value) && $value !== '' && mb_strlen($value) <= $max ? $value : null;
    }
}
