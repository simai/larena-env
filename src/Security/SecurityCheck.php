<?php

declare(strict_types=1);

namespace Larena\Env\Security;

/**
 * One check of the server's security (security:status, simai-security/1, simai-env 1.45.0): what it looks at, how it
 * stands and, where the server can put it right itself, the gateway command that does it. The panel says the check in
 * its own words by its kind (`check`); the server's title and detail stand only for a kind the panel does not know.
 */
final readonly class SecurityCheck
{
    public const array STATUSES = ['risk', 'warn', 'ok', 'unknown'];
    public const array SEVERITIES = ['low', 'medium', 'high', 'critical'];

    /** @param array<string, string>|null $fixArgs */
    public function __construct(
        public string $id,
        public string $check,
        public string $area,
        public string $status,
        public string $severity,
        public ?string $domain,
        public ?string $title,
        public ?string $detail,
        public ?string $how,
        public ?string $fixCommand,
        public ?array $fixArgs,
    ) {
    }

    public function isProblem(): bool
    {
        return $this->status === 'risk' || $this->status === 'warn';
    }

    /** @param array<array-key, mixed> $data */
    public static function fromData(array $data): ?self
    {
        $id = $data['id'] ?? null;
        $check = $data['check'] ?? null;
        if (!is_string($id) || preg_match('/\A[a-z][a-z0-9_]{0,40}(?::[A-Za-z0-9._-]{1,253})?\z/D', $id) !== 1
            || !is_string($check) || preg_match('/\A[a-z][a-z0-9_]{0,40}\z/D', $check) !== 1) {
            return null;
        }
        $status = in_array($data['status'] ?? null, self::STATUSES, true) ? $data['status'] : 'unknown';
        $text = static fn (string $key): ?string => is_string($data[$key] ?? null) && trim($data[$key]) !== '' ? mb_substr(trim($data[$key]), 0, 500) : null;
        $fix = is_array($data['fix'] ?? null) ? $data['fix'] : null;
        $command = is_string($fix['command'] ?? null) && preg_match('/\A[a-z]+:[a-z-]+\z/D', $fix['command']) === 1 ? $fix['command'] : null;
        $args = null;
        if ($command !== null) {
            $args = [];
            foreach (is_array($fix['args'] ?? null) ? $fix['args'] : [] as $name => $value) {
                if (is_string($name) && (is_string($value) || is_int($value))) {
                    $args[$name] = (string) $value;
                }
            }
        }
        $domain = $text('domain');

        return new self(
            id: $id,
            check: $check,
            area: is_string($data['area'] ?? null) && preg_match('/\A[a-z]{2,24}\z/D', $data['area']) === 1 ? $data['area'] : 'other',
            status: $status,
            severity: in_array($data['severity'] ?? null, self::SEVERITIES, true) ? $data['severity'] : 'medium',
            domain: $domain !== null && preg_match('/\A[A-Za-z0-9.-]{1,253}\z/D', $domain) === 1 ? strtolower($domain) : null,
            title: $text('title'),
            detail: $text('detail'),
            how: $text('how'),
            fixCommand: $command,
            fixArgs: $args,
        );
    }
}
