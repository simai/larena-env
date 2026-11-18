<?php

declare(strict_types=1);

namespace Larena\Env\Server;

use UnexpectedValueException;

/**
 * The last lines of a site's nginx log (simai-log-tail/1), as the gateway hands them over; since simai-env 1.60.0 with
 * the server's reading of each line (`format: json` entries), null from an older server.
 */
final readonly class NginxLogTail
{
    private const string TAIL_SCHEMA = 'simai-log-tail/1';

    /**
     * @param list<string> $lines
     * @param list<LogEntry>|null $entries
     */
    public function __construct(public string $domain, public string $kind, public array $lines, public ?array $entries = null)
    {
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @throws UnexpectedValueException
     */
    public static function fromData(array $data): self
    {
        $domain = $data['domain'] ?? null;
        $kind = $data['kind'] ?? null;
        if (($data['schema'] ?? null) !== self::TAIL_SCHEMA || !is_string($domain) || !is_string($kind)) {
            throw new UnexpectedValueException('log_field_invalid');
        }
        $lines = [];
        foreach (is_array($data['lines'] ?? null) ? $data['lines'] : [] as $line) {
            if (!is_string($line)) {
                throw new UnexpectedValueException('log_field_invalid');
            }
            $lines[] = $line;
        }

        return new self($domain, $kind, $lines, LogEntry::listOf($data['entries'] ?? null));
    }
}
