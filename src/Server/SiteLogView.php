<?php

declare(strict_types=1);

namespace Larena\Env\Server;

/**
 * The last lines of a site's log as rows a person can search and narrow (Panel plan 3.6): a request line is read into
 * its time, address, method, path and status; an error line into its time and level. A line in another form stays a
 * line of text. Newest first: what happened last is what a person looks for.
 */
final readonly class SiteLogView
{
    /** The status groups of a request log, and the levels of an error log, a person narrows by. */
    public const array STATUS_GROUPS = ['2xx', '3xx', '4xx', '5xx'];
    public const array LEVELS = ['emerg', 'alert', 'crit', 'error', 'warn', 'notice', 'info'];

    private const string REQUEST = '/\A(?<ip>\S+) \S+ \S+ \[(?<time>[^\]]+)\] "(?<method>[A-Z]+) (?<path>\S+)[^"]*" (?<status>[0-9]{3}) /';
    private const string ERROR = '/\A(?<time>[0-9]{4}\/[0-9]{2}\/[0-9]{2} [0-9:]{8}) \[(?<level>[a-z]+)\] (?<message>.*)\z/s';

    /**
     * @param list<array{time: ?string, ip: ?string, method: ?string, path: ?string, status: ?string, level: ?string, text: string}> $rows
     */
    public function __construct(public string $kind, public array $rows, public int $total)
    {
    }

    /**
     * @param string $search words that must stand in the line, any case
     * @param string $group a status group of a request log (`4xx`) or a level of an error log (`error`); empty for all
     */
    public static function of(NginxLogTail $tail, string $search = '', string $group = ''): self
    {
        $rows = [];
        $search = mb_strtolower(trim($search));
        foreach (array_reverse($tail->lines) as $line) {
            if ($search !== '' && !str_contains(mb_strtolower($line), $search)) {
                continue;
            }
            $row = $tail->kind === 'error' ? self::errorRow($line) : self::requestRow($line);
            if ($group !== '' && !self::inGroup($row, $tail->kind, $group)) {
                continue;
            }
            $rows[] = $row;
        }

        return new self($tail->kind, $rows, count($tail->lines));
    }

    /** @param array{status: ?string, level: ?string} $row */
    private static function inGroup(array $row, string $kind, string $group): bool
    {
        return $kind === 'error'
            ? $row['level'] === $group
            : $row['status'] !== null && $row['status'][0] === $group[0];
    }

    /** @return array{time: ?string, ip: ?string, method: ?string, path: ?string, status: ?string, level: ?string, text: string} */
    private static function requestRow(string $line): array
    {
        if (preg_match(self::REQUEST, $line, $match) === 1) {
            return ['time' => $match['time'], 'ip' => $match['ip'], 'method' => $match['method'], 'path' => $match['path'],
                'status' => $match['status'], 'level' => null, 'text' => $line];
        }

        return ['time' => null, 'ip' => null, 'method' => null, 'path' => null, 'status' => null, 'level' => null, 'text' => $line];
    }

    /** @return array{time: ?string, ip: ?string, method: ?string, path: ?string, status: ?string, level: ?string, text: string} */
    private static function errorRow(string $line): array
    {
        if (preg_match(self::ERROR, $line, $match) === 1) {
            return ['time' => $match['time'], 'ip' => null, 'method' => null, 'path' => null, 'status' => null, 'level' => $match['level'],
                'text' => $match['message']];
        }

        return ['time' => null, 'ip' => null, 'method' => null, 'path' => null, 'status' => null, 'level' => null, 'text' => $line];
    }
}
