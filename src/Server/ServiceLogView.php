<?php

declare(strict_types=1);

namespace Larena\Env\Server;

/**
 * «Журнал службы» read by people (PX-48): newest first, the errors and warnings first of all where there are any, each
 * line's moment, its importance as a badge and the line as the server wrote it on a press. The server reads journald
 * itself since simai-env 1.60.0 (`format: json`: the moment with its zone, the priority as a level) and keeps the
 * errors and warnings on its side (`priority: warning`); the panel no longer guesses a level from words. An older
 * server's short lines are shown as written, without a badge.
 */
final readonly class ServiceLogView
{
    /** What a person looks at: the errors and warnings, or every line. */
    public const array VIEWS = ['problems', 'all'];
    public const int PAGE = 50;
    /** The priority the server keeps for «Ошибки и предупреждения»: that level and above. */
    public const string PROBLEMS_PRIORITY = 'warning';

    private const string LINE = '/\A(?<time>[A-Z][a-z]{2} +[0-9]{1,2} [0-9]{2}:[0-9]{2}:[0-9]{2}) (?<host>\S+) (?<unit>[^:\s]+): (?<message>.*)\z/s';

    /**
     * @param list<array{at: string, time: string, badge: ?array{text: string, status: string}, title: string, note: string, raw: string, problem: bool}> $rows
     */
    public function __construct(public string $view, public array $rows, public int $total, public int $problems)
    {
    }

    /**
     * @param list<string> $lines the journal's lines, oldest first, as the server gives them
     * @param list<LogEntry>|null $entries the server's reading of them (1.60.0), null from an older server
     * @param \Closure(string): string $words a tone (`error`, `warning`) in words
     * @param bool $problemsOnly the server already kept only the errors and warnings (`priority: warning`)
     */
    public static function of(array $lines, ?array $entries, string $view, \Closure $words, bool $problemsOnly = false): self
    {
        $rows = [];
        if ($entries !== null) {
            foreach (array_reverse($entries) as $entry) {
                $rows[] = self::entryRow($entry, $words);
            }
        } else {
            foreach (array_reverse($lines) as $line) {
                $rows[] = self::lineRow($line);
            }
        }
        $problems = count(array_filter($rows, static fn (array $row): bool => $row['problem']));
        // Errors first: the screen opens on them when there are any; «Все записи» shows the rest.
        $view = match (true) {
            $entries === null => 'all',
            $problemsOnly => 'problems',
            in_array($view, self::VIEWS, true) => $view,
            default => $problems > 0 ? 'problems' : 'all',
        };
        if ($view === 'problems') {
            $rows = array_values(array_filter($rows, static fn (array $row): bool => $row['problem']));
        }

        return new self($view, $rows, count($entries ?? $lines), $problems);
    }

    /** @return array{at: string, time: string, badge: ?array{text: string, status: string}, title: string, note: string, raw: string, problem: bool} */
    private static function entryRow(LogEntry $entry, \Closure $words): array
    {
        $tone = $entry->tone();

        return [
            'at' => $entry->at ?? '',
            'time' => '',
            'badge' => $tone === null ? null : ['text' => $words($tone), 'status' => $tone],
            'title' => $entry->message ?? $entry->raw,
            'note' => $entry->unit === null ? '' : preg_replace('/\.service\z/', '', $entry->unit) ?? '',
            'raw' => $entry->raw,
            'problem' => $tone !== null,
        ];
    }

    /** An older server's line: its own time as written (no year, no zone), its unit, no level guessed. */
    private static function lineRow(string $line): array
    {
        $match = [];
        $parsed = preg_match(self::LINE, $line, $match) === 1;

        return [
            'at' => '',
            'time' => $parsed ? $match['time'] : '',
            'badge' => null,
            'title' => $parsed ? $match['message'] : $line,
            'note' => $parsed ? preg_replace('/\[[0-9]+\]\z/', '', $match['unit']) ?? '' : '',
            'raw' => $line,
            'problem' => false,
        ];
    }
}
