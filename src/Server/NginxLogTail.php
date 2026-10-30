<?php

declare(strict_types=1);

namespace Larena\Env\Server;

use UnexpectedValueException;

/** The last lines of a site's nginx log (simai-log-tail/1), as the gateway hands them over. */
final readonly class NginxLogTail
{
    private const string TAIL_SCHEMA = 'simai-log-tail/1';

    /** @param list<string> $lines */
    public function __construct(public string $domain, public string $kind, public array $lines)
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

        return new self($domain, $kind, $lines);
    }
}
