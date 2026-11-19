<?php

declare(strict_types=1);

namespace Larena\Env\Admin;

/**
 * «Восстановить из архива» (site:restore-ready, simai-env 1.57.1) in the panel's words: what the plan does, and why the
 * server refuses it. Since simai-env 1.63.4 the server names its refusal by a code (`reason`) and gives what its check
 * found (`restore`, simai-site-restore-plan/1: the site's folders, the archive and the dump it resolved, the database and
 * its tables, whether the site already has files, whether a backup is taken); the panel says each code as one Russian
 * sentence and shows the found things as facts. The server's own English lines stay under «Подробнее».
 */
final class RestoreWords
{
    /** The refusals the server names for this command (simai-env 1.63.4), and the gateway's for a path out of the site. */
    public const array REFUSALS = ['site_missing', 'profile_bitrix', 'profile_wordpress', 'profile_unsupported', 'releases', 'root_missing',
        'path_invalid', 'file_missing', 'symlink', 'not_file', 'files_is_dump', 'archive_kind', 'dump_kind', 'no_database',
        'engine_unsupported', 'database_unreadable', 'database_not_empty', 'files_present', 'invalid_argument'];

    /** The refusals a tick of «Заменить существующее» answers: the data is there, and the server keeps a copy first. */
    public const array OVERWRITE = ['database_not_empty', 'files_present'];

    /** The refusals about the archive or the dump themselves: the way to the site's files, where they are uploaded. */
    public const array TO_FILES = ['path_invalid', 'file_missing', 'symlink', 'not_file', 'files_is_dump', 'archive_kind', 'dump_kind', 'invalid_argument'];

    private const string SCHEMA = 'simai-site-restore-plan/1';

    private function __construct()
    {
    }

    /**
     * What the server's check found (simai-site-restore-plan/1), or null for another shape or an older server.
     *
     * @return array{root: ?string, doc_root: ?string, public_dir: ?string, files: ?string, dump: ?string, database: array{exists: bool, engine: ?string, name: ?string, tables: ?int}, files_present: bool, overwrite: bool, backup: bool}|null
     */
    public static function found(mixed $data): ?array
    {
        if (!is_array($data) || ($data['schema'] ?? null) !== self::SCHEMA) {
            return null;
        }
        $text = static fn (mixed $value): ?string => is_string($value) && $value !== '' && mb_strlen($value) <= 1024 ? $value : null;
        $database = is_array($data['database'] ?? null) ? $data['database'] : [];

        return [
            'root' => $text($data['root'] ?? null),
            'doc_root' => $text($data['doc_root'] ?? null),
            'public_dir' => $text($data['public_dir'] ?? null),
            'files' => $text($data['files'] ?? null),
            'dump' => $text($data['dump'] ?? null),
            'database' => [
                'exists' => ($database['exists'] ?? false) === true,
                'engine' => $text($database['engine'] ?? null),
                'name' => $text($database['name'] ?? null),
                'tables' => is_int($database['tables'] ?? null) && $database['tables'] >= 0 ? $database['tables'] : null,
            ],
            'files_present' => ($data['files_present'] ?? false) === true,
            'overwrite' => ($data['overwrite'] ?? false) === true,
            'backup' => ($data['backup'] ?? false) === true,
        ];
    }

    /**
     * A refusal of the server as the panel says it, or null for a reason that is not this command's.
     *
     * @param array<string, string> $args what the person chose (files, dump)
     * @param array<string, mixed>|null $found self::found() of the refusal
     * @param callable(string, array<string, string>): string $text the panel's words by key under `restore.`
     */
    public static function refusal(string $reason, array $args, ?array $found, callable $text): ?string
    {
        if (!in_array($reason, self::REFUSALS, true)) {
            return null;
        }
        $files = trim($args['files'] ?? '');
        $dump = trim($args['dump'] ?? '');
        // The file a refusal is about: the archive, unless the server resolved it and the dump is what it stopped at.
        $aboutDump = $dump !== '' && $found !== null && $found['files'] !== null && $found['dump'] === null;
        $name = $aboutDump ? $dump : ($files !== '' ? $files : $dump);

        return $text('refusals.'.($reason === 'invalid_argument' ? 'path_invalid' : $reason), [
            'what' => $text('refusal_what.'.($aboutDump ? 'dump' : 'files'), []),
            'name' => basename($name),
            'count' => (string) ($found['database']['tables'] ?? ''),
            'engine' => (string) ($found['database']['engine'] ?? ''),
        ]);
    }

    /**
     * The plan in words: the site, the archive unpacked into its folder, the dump loaded into its database or none, what
     * is there and replaced, the copy taken before, and what follows — from what the server found where it says it
     * (simai-env 1.63.4), else from the person's choices.
     *
     * @param array<string, string> $args the plan's arguments (files, dump, overwrite)
     * @param array<string, mixed>|null $found self::found() of the plan
     * @param callable(string, array<string, string>): string $text the panel's words by key under `restore.`
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function facts(string $target, array $args, ?array $found, callable $text): array
    {
        $dump = trim((string) ($found['dump'] ?? $args['dump'] ?? ''));
        $files = (string) ($found['files'] ?? $args['files'] ?? '');
        $overwrite = $found !== null ? $found['overwrite'] : ($args['overwrite'] ?? 'no') === 'yes';
        $facts = [
            [$text('fact.site', []), $target],
            [$text('fact.files', []), $text('fact.files_value', ['name' => $files])],
        ];
        if ($found !== null && $found['root'] !== null) {
            $facts[] = [$text('fact.folder', []), $found['public_dir'] !== null && $found['doc_root'] !== null && $found['doc_root'] !== $found['root']
                ? $text('fact.folder_public', ['root' => $found['root'], 'public' => $found['public_dir']]) : $found['root']];
        }
        if ($dump === '') {
            $facts[] = [$text('fact.database', []), $text('fact.database_none', [])];
        } else {
            $database = $found['database'] ?? null;
            $tables = $database['tables'] ?? null;
            $facts[] = [$text('fact.database', []), $database !== null && $database['name'] !== null
                ? $text($tables === null ? 'fact.database_into' : ($tables === 0 ? 'fact.database_into_empty' : 'fact.database_into_tables'),
                    ['name' => $dump, 'database' => $database['name'], 'count' => (string) $tables])
                : $text('fact.database_value', ['name' => $dump])];
        }
        if ($found !== null) {
            $present = $found['files_present'] || ($dump !== '' && ($found['database']['tables'] ?? 0) > 0);
            $facts[] = [$text('fact.replaces', []), $text($present ? ($overwrite ? 'fact.replaces_yes' : 'fact.replaces_no') : 'fact.replaces_nothing', [])];
            $facts[] = [$text('fact.backup', []), $text($found['backup'] ? 'fact.backup_yes' : 'fact.backup_no', [])];
        } else {
            $facts[] = [$text('fact.replaces', []), $text($overwrite ? 'fact.replaces_yes' : 'fact.replaces_no', [])];
            $facts[] = [$text('fact.backup', []), $text($overwrite ? 'fact.backup_yes' : 'fact.backup_no', [])];
        }
        $facts[] = [$text('fact.then', []), $text($dump === '' ? 'fact.then_files' : 'fact.then_dump', [])];

        return $facts;
    }

    /**
     * What the plan's plate says it changes: replaced data after a copy, or nothing replaced at all.
     *
     * @param array<string, string> $args
     * @param array<string, mixed>|null $found self::found() of the plan
     * @param callable(string, array<string, string>): string $text
     */
    public static function consequence(array $args, ?array $found, callable $text): string
    {
        $replaces = $found !== null ? $found['backup'] : ($args['overwrite'] ?? 'no') === 'yes';

        return $text($replaces ? 'consequence_overwrite' : 'consequence_empty', []);
    }
}
