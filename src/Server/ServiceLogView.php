<?php

declare(strict_types=1);

namespace Larena\Env\Server;

/**
 * «Журнал службы» read by people (PX-48): newest first, the errors and warnings first of all where there are any, each
 * line's moment in the reader's time, its importance as a badge and the line as the server wrote it on a press. The
 * server gives a service's journal as journald's short lines («Nov 18 00:10:59 host nginx[728]: …»): no year and no
 * time zone, so the moment is read as the server's clock in UTC within the last year (asked of simai-env in ISO form).
 */
final readonly class ServiceLogView
{
    /** What a person looks at: the errors and warnings, or every line. */
    public const array VIEWS = ['problems', 'all'];
    public const int PAGE = 50;

    private const string LINE = '/\A(?<time>[A-Z][a-z]{2} +[0-9]{1,2} [0-9]{2}:[0-9]{2}:[0-9]{2}) (?<host>\S+) (?<unit>[^:\s]+): (?<message>.*)\z/s';
    private const string ERROR = '/\b(emerg|alert|crit|critical|error|err|fail|failed|failure|fatal|denied|refused)\b/i';
    private const string WARNING = '/\b(warn|warning)\b/i';

    /**
     * @param list<array{at: string, time: string, badge: ?array{text: string, status: string}, title: string, note: string, raw: string, problem: bool}> $rows
     */
    public function __construct(public string $view, public array $rows, public int $total, public int $problems)
    {
    }

    /**
     * @param list<string> $lines the journal's lines, oldest first, as the server gives them
     * @param \Closure(string): string $words a tone (`error`, `warning`) in words
     */
    public static function of(array $lines, string $view, \Closure $words, ?\DateTimeImmutable $now = null): self
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $rows = [];
        foreach (array_reverse($lines) as $line) {
            $rows[] = self::row($line, $words, $now);
        }
        $problems = count(array_filter($rows, static fn (array $row): bool => $row['problem']));
        // Errors first: the screen opens on them when there are any; «Все записи» shows the rest.
        $view = in_array($view, self::VIEWS, true) ? $view : ($problems > 0 ? 'problems' : 'all');
        if ($view === 'problems') {
            $rows = array_values(array_filter($rows, static fn (array $row): bool => $row['problem']));
        }

        return new self($view, $rows, count($lines), $problems);
    }

    /** @return array{at: string, time: string, badge: ?array{text: string, status: string}, title: string, note: string, raw: string, problem: bool} */
    private static function row(string $line, \Closure $words, \DateTimeImmutable $now): array
    {
        $match = [];
        $parsed = preg_match(self::LINE, $line, $match) === 1;
        $message = $parsed ? $match['message'] : $line;
        $tone = preg_match(self::ERROR, $message) === 1 ? 'error' : (preg_match(self::WARNING, $message) === 1 ? 'warning' : null);

        return [
            'at' => $parsed ? (self::iso($match['time'], $now) ?? '') : '',
            'time' => $parsed ? $match['time'] : '',
            'badge' => $tone === null ? null : ['text' => $words($tone), 'status' => $tone],
            'title' => $message,
            'note' => $parsed ? preg_replace('/\[[0-9]+\]\z/', '', $match['unit']) ?? '' : '',
            'raw' => $line,
            'problem' => $tone !== null,
        ];
    }

    /** A journald moment without a year, in ISO 8601: this year's, or last year's when this year's is still ahead. */
    public static function iso(string $time, \DateTimeImmutable $now): ?string
    {
        $utc = new \DateTimeZone('UTC');
        $moment = \DateTimeImmutable::createFromFormat('!Y M j H:i:s', $now->format('Y').' '.preg_replace('/ +/', ' ', $time), $utc);
        if ($moment === false) {
            return null;
        }
        if ($moment > $now->modify('+1 day')) {
            $moment = $moment->modify('-1 year');
        }

        return $moment->format('Y-m-d\TH:i:s\Z');
    }
}
