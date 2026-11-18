<?php

declare(strict_types=1);

namespace Larena\Env\Admin;

/**
 * «Восстановить из архива» (site:restore-ready, simai-env 1.57.1) in the panel's words: what the plan does, and why the
 * server refuses it. The server names no refusal for this command (its plan says `plan_failed` with its own English
 * line, simai-env 1.63.3), so each line it writes is read here by its shape and said as one Russian sentence; a line the
 * panel does not know stays as the server wrote it.
 */
final class RestoreWords
{
    /** The server's lines (admin/commands/site_restore.sh, wp_site_file) and the refusal each one is. */
    private const array LINES = [
        'site_missing' => '/\ADomain is not configured as a site\b/',
        'bitrix' => '/ is a Bitrix site: use bitrix restore-ready\z/',
        'wordpress' => '/ is a WordPress site: use wp restore-ready\z/',
        'not_php' => '/\Asite restore-ready is for PHP sites\b/',
        'releases' => '/ is deployed in releases; restore it with site deploy --archive\z/',
        'root_missing' => '/\ASite directory .+ is missing\z/',
        'path' => '/\A--(?<what>files|dump) (?:is empty or has control characters|must not contain|must be inside )/',
        'missing' => '/\A--(?<what>files|dump): (?<name>.+) not found in /',
        'link' => '/\A--(?<what>files|dump): (?<name>.+) goes through a symlink; refused\z/',
        'not_file' => '/\A--(?<what>files|dump): (?<name>.+) is not a regular file\z/',
        'files_is_dump' => '/\A--files is the site\'s files; (?<name>.+) is a dump \(--dump\)\z/',
        'files_kind' => '/\A--files must be a /',
        'dump_kind' => '/\A--dump must be a /',
        'no_database' => '/ has no database for the dump: /',
        'not_mysql' => '/\AThe dump can be imported into MySQL only; .+ uses (?<engine>\S+)\z/',
        'database_unreadable' => '/\A(?:The site database name is not valid|Cannot read the database )/',
        'database_not_empty' => '/\AThe database \S+ is not empty \((?<count>\d+) tables?\); rerun with --overwrite yes/',
        'files_present' => '/ already has files; rerun with --overwrite yes/',
    ];

    /** The refusals a tick of «Заменить существующее» answers: the data is there, and the server keeps a copy first. */
    public const array OVERWRITE = ['database_not_empty', 'files_present'];

    private function __construct()
    {
    }

    /**
     * The refusal a server's line is, with what it names; null for a line the panel does not know.
     *
     * @return array{code: string, what: ?string, name: ?string, count: ?string, engine: ?string}|null
     */
    public static function refusal(string $line): ?array
    {
        foreach (self::LINES as $code => $pattern) {
            if (preg_match($pattern, trim($line), $found) === 1) {
                return ['code' => $code, 'what' => $found['what'] ?? null, 'name' => $found['name'] ?? null,
                    'count' => $found['count'] ?? null, 'engine' => $found['engine'] ?? null];
            }
        }

        return null;
    }

    /**
     * The server's lines as the panel says them: each known one as its sentence, any other as written.
     *
     * @param list<string> $lines
     * @param callable(string, array<string, string>): string $text the panel's words by key under `restore.`
     *
     * @return array{said: list<string>, codes: list<string>, all: bool}
     */
    public static function refusals(array $lines, callable $text): array
    {
        $said = [];
        $codes = [];
        $all = $lines !== [];
        foreach ($lines as $line) {
            $found = self::refusal($line);
            if ($found === null) {
                $said[] = $line;
                $all = false;

                continue;
            }
            $codes[] = $found['code'];
            $said[] = $text('refusals.'.$found['code'], [
                'what' => $found['what'] === null ? '' : $text('refusal_what.'.$found['what'], []),
                'name' => (string) $found['name'],
                'count' => (string) $found['count'],
                'engine' => (string) $found['engine'],
            ]);
        }

        return ['said' => array_values(array_unique($said)), 'codes' => $codes, 'all' => $all];
    }

    /**
     * The plan in words: the site, the archive unpacked into it, the dump loaded into its database or none, what is
     * replaced, the copy taken before, and what follows.
     *
     * @param array<string, string> $args the plan's arguments (files, dump, overwrite)
     * @param callable(string, array<string, string>): string $text the panel's words by key under `restore.`
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function facts(string $target, array $args, callable $text): array
    {
        $dump = trim($args['dump'] ?? '');
        $overwrite = ($args['overwrite'] ?? 'no') === 'yes';

        return [
            [$text('fact.site', []), $target],
            [$text('fact.files', []), $text('fact.files_value', ['name' => (string) ($args['files'] ?? '')])],
            [$text('fact.database', []), $dump === '' ? $text('fact.database_none', []) : $text('fact.database_value', ['name' => $dump])],
            [$text('fact.replaces', []), $text($overwrite ? 'fact.replaces_yes' : 'fact.replaces_no', [])],
            [$text('fact.backup', []), $text($overwrite ? 'fact.backup_yes' : 'fact.backup_no', [])],
            [$text('fact.then', []), $text($dump === '' ? 'fact.then_files' : 'fact.then_dump', [])],
        ];
    }

    /**
     * What the plan's plate says it changes: replaced data after a copy, or nothing replaced at all.
     *
     * @param array<string, string> $args
     * @param callable(string, array<string, string>): string $text
     */
    public static function consequence(array $args, callable $text): string
    {
        return $text(($args['overwrite'] ?? 'no') === 'yes' ? 'consequence_overwrite' : 'consequence_empty', []);
    }
}
