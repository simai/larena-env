<?php

declare(strict_types=1);

namespace Larena\Env\Server;

/**
 * The last lines of a site's log as rows a person can search and narrow (Panel plan 3.6): a request line is read into
 * its time, address, method, path and status; an error line into its time and level. The server reads them itself since
 * simai-env 1.60.0 (`format: json` entries, each moment with its zone); an older server's lines are read here, and an
 * error-log time without a zone stays the server's own words. A line in another form stays a line of text. Newest
 * first: what happened last is what a person looks for.
 */
final readonly class SiteLogView
{
    /** The status groups of a request log, and the levels of an error log, a person narrows by. */
    public const array STATUS_GROUPS = ['2xx', '3xx', '4xx', '5xx'];
    public const array LEVELS = ['emerg', 'alert', 'crit', 'error', 'warn', 'notice', 'info'];
    /**
     * What a person looks at (PX-48): the site's errors first — the requests answered 4xx or 5xx — then every visit and
     * the web server's own error log. The first two read the request log.
     */
    public const array VIEWS = ['problems', 'access', 'error'];
    /** The rows shown at once, then «Ещё 50». */
    public const int PAGE = 50;
    /** The group of `problems`: a request answered with an error. */
    public const string PROBLEMS = 'problems';

    private const string REQUEST = '/\A(?<ip>\S+) \S+ \S+ \[(?<time>[^\]]+)\] "(?<method>[A-Z]+) (?<path>\S+)[^"]*" (?<status>[0-9]{3}) /';
    private const string ERROR = '/\A(?<time>[0-9]{4}\/[0-9]{2}\/[0-9]{2} [0-9:]{8}) \[(?<level>[a-z]+)\] (?<message>.*)\z/s';

    /**
     * @param list<array{time: ?string, at?: ?string, ip: ?string, method: ?string, path: ?string, status: ?string, level: ?string, text: string, raw?: string}> $rows
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
        $read = $tail->entries !== null
            ? array_map(self::entryRow(...), $tail->entries)
            : array_map(static fn (string $line): array => $tail->kind === 'error' ? self::errorRow($line) : self::requestRow($line), $tail->lines);
        foreach (array_reverse($read) as $row) {
            if ($search !== '' && !str_contains(mb_strtolower($row['raw'] ?? $row['text']), $search)) {
                continue;
            }
            if ($group !== '' && !self::inGroup($row, $tail->kind, $group)) {
                continue;
            }
            $rows[] = $row;
        }

        return new self($tail->kind, $rows, count($read));
    }

    /**
     * A line as the server read it: an access entry keeps its answer, an error entry its level and message.
     *
     * @return array{time: ?string, at: ?string, ip: ?string, method: ?string, path: ?string, status: ?string, level: ?string, text: string, raw: string}
     */
    private static function entryRow(LogEntry $entry): array
    {
        $status = $entry->status === null ? null : (string) $entry->status;

        return ['time' => null, 'at' => $entry->at, 'ip' => $entry->ip, 'method' => $status === null ? null : $entry->method,
            'path' => $entry->path, 'status' => $status, 'level' => $status === null ? $entry->level : null,
            'text' => $entry->message ?? $entry->raw, 'raw' => $entry->raw];
    }

    /** @param array{status: ?string, level: ?string} $row */
    private static function inGroup(array $row, string $kind, string $group): bool
    {
        if ($group === self::PROBLEMS) {
            return $kind === 'error' || ($row['status'] !== null && in_array($row['status'][0], ['4', '5'], true));
        }

        return $kind === 'error'
            ? $row['level'] === $group
            : $row['status'] !== null && $row['status'][0] === $group[0];
    }

    /**
     * The rows as the admin's log viewer draws them (larena-admin::history.log): the moment in ISO 8601, shown in the
     * reader's time; the answer or the level as a badge; the request or the message; where it came from; the line as
     * the server wrote it.
     *
     * @param (\Closure(string): string)|null $words a level in words
     *
     * @return list<array{at: string, time: string, badge: ?array{text: string, status: string}, title: string, note: string, raw: string}>
     */
    public function display(int $limit, ?\Closure $words = null): array
    {
        $shown = [];
        foreach (array_slice($this->rows, 0, max(0, $limit)) as $row) {
            $badge = $row['status'] !== null ? ['text' => $row['status'], 'status' => self::statusTone($row['status'])]
                : ($row['level'] !== null ? ['text' => $words === null ? $row['level'] : $words($row['level']), 'status' => self::levelTone($row['level'])] : null);
            $shown[] = [
                'at' => $row['at'] ?? self::iso($row['time'], $this->kind) ?? '',
                'time' => $row['time'] ?? '',
                'badge' => $badge,
                'title' => $row['method'] !== null ? $row['method'].' '.($row['path'] ?? '') : $row['text'],
                'note' => $row['ip'] ?? '',
                'raw' => $row['raw'] ?? $row['text'],
            ];
        }

        return $shown;
    }

    /**
     * A request line's moment in ISO 8601: nginx writes `17/Nov/2026:10:11:12 +0300`, with its zone. The error log's
     * `2026/11/17 10:11:12` has none: it is the server's local time, which the panel does not guess (the server says it
     * with its zone since simai-env 1.60.0), so it stays the server's own words.
     */
    public static function iso(?string $time, string $kind): ?string
    {
        if ($time === null || $kind === 'error') {
            return null;
        }
        $moment = \DateTimeImmutable::createFromFormat('!d/M/Y:H:i:s O', $time);

        return $moment === false ? null : $moment->format('Y-m-d\TH:i:sP');
    }

    private static function statusTone(string $status): string
    {
        return match ($status[0]) {'2' => 'success', '3' => 'info', '4' => 'warning', default => 'error'};
    }

    private static function levelTone(string $level): string
    {
        return match ($level) {'emerg', 'alert', 'crit', 'error', 'err' => 'error', 'warn', 'warning' => 'warning', default => 'info'};
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

    /** @return array{time: ?string, ip: ?string, method: ?string, path: ?string, status: ?string, level: ?string, text: string, raw?: string} */
    private static function errorRow(string $line): array
    {
        if (preg_match(self::ERROR, $line, $match) === 1) {
            return ['time' => $match['time'], 'ip' => null, 'method' => null, 'path' => null, 'status' => null, 'level' => $match['level'],
                'text' => $match['message'], 'raw' => $line];
        }

        return ['time' => null, 'ip' => null, 'method' => null, 'path' => null, 'status' => null, 'level' => null, 'text' => $line];
    }
}
