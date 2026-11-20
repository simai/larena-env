<?php

declare(strict_types=1);

namespace Larena\Env\Admin;

use Larena\Admin\Runtime\AdminDataviewPage;
use Larena\Env\Files\FileEntry;
use Larena\Env\Files\FilePath;
use Larena\Env\Files\TrashEntry;
use Larena\Env\Server\Bytes;
use Larena\Env\Server\Moment;

/**
 * A site's folder and its trash as the admin's shared data view, with rows in place (files study PX-93, PX-94, PX-100):
 * the name opens a folder or a file, a file has «Посмотреть» and «Скачать» in its row and the rest under «⋯», the time is
 * said as «2 ч назад» with the reader's moment in the tooltip, a file with passwords is marked «секреты» and is not
 * downloaded from the row. On a phone the same rows as a list, without a label in every row. Each change is the panel's
 * plan: removing goes to the site's trash where the server keeps one.
 */
final class SiteFilesPage
{
    /** The entries of a site's root that are the server's, not the site's: folded under «Служебные» (PX-91). */
    public const array SERVICE = ['releases', 'current', 'shared', '.simai-trash', '.simai', '.simai-env'];

    /** A row's changes under «⋯» that open their form in the side panel: rename, copy (simai-env 1.57.0), pack, unpack. */
    public const array ENTRY_OPERATIONS = ['rename', 'copy', 'archive', 'extract'];

    private function __construct()
    {
    }

    /**
     * @param list<FileEntry> $entries
     * @param array<string, bool> $allows the file actions this server allows (SiteFilesController::FILE_OPERATIONS, download, read)
     * @param callable(string, array<string, string>=): string $text
     */
    public static function folder(string $domain, string $path, array $entries, array $allows, callable $text, string $token, bool $trash, ?\DateTimeImmutable $now = null): AdminDataviewPage
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $site = '/admin/server/sites/'.$domain;
        $rows = array_map(static fn (FileEntry $entry): array => self::row($path, $entry, $allows, $text, $now), $entries);

        return new AdminDataviewPage(
            instanceId: 'env-site-files',
            title: $text('files.title'),
            columns: [
                ['key' => 'name', 'label' => $text('files.name'), 'role' => 'title', 'list' => 'title'],
                ['key' => 'size', 'label' => $text('files.size'), 'list' => 'supporting'],
                ['key' => 'changed', 'label' => $text('files.changed'), 'list' => 'fact'],
                ['key' => 'mark', 'label' => $text('files.mark'), 'list' => 'fact'],
            ],
            total: count($rows),
            pageId: 'page.admin.env-site-files',
            rows: $rows,
            perPage: 100,
            rowLinks: ['view' => $site.'?tab=files&open=__ID__']
                + ($allows['download'] ?? false ? ['download' => $site.'/files/download?path=__ID__'] : []),
            countLabel: $text('files.count'),
            tableViewLabel: $text('files.table_view'),
            rowWrites: ($allows['remove'] ?? false) ? ['delete' => ['url' => $site.'/files/plan', 'fields' => ['_token' => $token, 'op' => 'remove', 'path' => '__ID__'],
                'label' => $text($trash ? 'files.to_trash' : 'files.remove')]] : [],
            quickActions: ($allows['download'] ?? false) ? ['look', 'download'] : ['look'],
            rowActions: ['look' => ['label' => $text('files.look'), 'icon' => 'visibility', 'href' => $site.'?tab=files&open=__ID__']]
                + array_combine(self::ENTRY_OPERATIONS, array_map(static fn (string $op): array => ['label' => $text('files.operations.'.$op).'…',
                    'icon' => ['rename' => 'edit', 'copy' => 'content_copy', 'archive' => 'archive', 'extract' => 'unarchive'][$op],
                    'href' => $site.'?tab=files&path='.rawurlencode($path).'&op='.$op.'&entry=__ID__'], self::ENTRY_OPERATIONS)),
            emptyText: $text('files.empty'),
            emptyDescription: $text('files.empty_description'),
        );
    }

    /**
     * The trash: what was removed, from where, how long ago and until when it stays; «Вернуть» in the row and «Удалить
     * насовсем» under «⋯», each the panel's plan.
     *
     * @param list<TrashEntry> $entries
     * @param callable(string, array<string, string>=): string $text
     */
    public static function trash(string $domain, array $entries, bool $restore, bool $purge, callable $text, string $token, ?\DateTimeImmutable $now = null,
        bool $secrets = true): AdminDataviewPage
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $plan = '/admin/server/sites/'.$domain.'/files/plan';
        $rows = array_map(static fn (TrashEntry $entry): array => [
            'id' => $entry->id,
            'name' => $entry->name.($entry->type === 'dir' ? '/' : ''),
            'place' => $entry->path !== null ? $text('files.trash.from', ['path' => $entry->path]) : '',
            // «удалён» or «заменён» (simai-env 1.64.0), before when.
            'deleted' => self::why($entry->reason, self::when($entry->deletedAt, $now, $text), $text),
            'expires' => $entry->expiresAt === null ? '' : ['label' => $text('files.trash.until', ['time' => Moment::human($entry->expiresAt)]),
                'text' => $text('files.trash.until', ['time' => Moment::human($entry->expiresAt)])],
            // A file with passwords is marked here too, and put back only by a person with that right (q-4997ae4e93).
            'mark' => $entry->secret ? ['label' => $text('files.secret'), 'text' => $text('files.secret'), 'status' => 'warning',
                'tip' => $secrets ? $text('files.secret_tip') : $text('files.reasons.secret_files_not_allowed')] : '',
            '_actions' => array_values(array_filter([$restore && (!$entry->secret || $secrets) ? 'restore' : null, $purge ? 'purge' : null])),
        ], $entries);

        return new AdminDataviewPage(
            instanceId: 'env-site-trash',
            title: $text('files.trash.title'),
            columns: [
                ['key' => 'name', 'label' => $text('files.name'), 'role' => 'title', 'list' => 'title'],
                ['key' => 'place', 'label' => $text('files.trash.place'), 'list' => 'supporting'],
                ['key' => 'deleted', 'label' => $text('files.trash.deleted'), 'list' => 'fact'],
                ['key' => 'expires', 'label' => $text('files.trash.expires'), 'list' => 'fact'],
                ['key' => 'mark', 'label' => $text('files.mark'), 'list' => 'fact'],
            ],
            total: count($rows),
            pageId: 'page.admin.env-site-trash',
            rows: $rows,
            perPage: 100,
            countLabel: $text('files.count'),
            tableViewLabel: $text('files.table_view'),
            rowWrites: ($restore ? ['restore' => ['url' => $plan, 'fields' => ['_token' => $token, 'op' => 'trash-restore', 'id' => '__ID__'], 'label' => $text('files.trash.restore')]] : [])
                + ($purge ? ['purge' => ['url' => $plan, 'fields' => ['_token' => $token, 'op' => 'trash-empty', 'id' => '__ID__'], 'label' => $text('files.trash.purge')]] : []),
            quickActions: $restore ? ['restore'] : [],
            emptyText: $text('files.trash.empty'),
            emptyDescription: $text('files.trash.empty_description'),
        );
    }

    /**
     * @param array<string, bool> $allows
     * @param callable(string, array<string, string>=): string $text
     * @return array<string, mixed>
     */
    private static function row(string $folder, FileEntry $entry, array $allows, callable $text, \DateTimeImmutable $now): array
    {
        $file = $entry->type === 'file';
        $secret = $entry->isSecret();
        // Without the right to files with passwords (q-4997ae4e93) such a file is listed and marked, and its name opens the
        // refusal: its row offers nothing the server would refuse.
        $closed = $secret && !($allows['secrets'] ?? false);
        $actions = ['view'];
        if ($closed) {
            return self::cells($folder, $entry, ['view'], $text, $now, $text('files.reasons.secret_files_not_allowed'));
        }
        if ($file && (($allows['read'] ?? false) || $entry->isImage())) {
            $actions[] = 'look';
        }
        if ($file && !$secret && ($allows['download'] ?? false)) {
            $actions[] = 'download';
        }
        foreach (['rename', 'copy', 'archive'] as $op) {
            if ($allows[$op] ?? false) {
                $actions[] = $op;
            }
        }
        if ($entry->isArchive() && ($allows['extract'] ?? false)) {
            $actions[] = 'extract';
        }
        if ($allows['remove'] ?? false) {
            $actions[] = 'delete';
        }

        return self::cells($folder, $entry, $entry->type === 'link' ? array_values(array_intersect($actions, ['rename', 'copy', 'delete'])) : $actions, $text, $now);
    }

    /**
     * @param list<string> $actions
     * @param callable(string, array<string, string>=): string $text
     * @return array<string, mixed>
     */
    private static function cells(string $folder, FileEntry $entry, array $actions, callable $text, \DateTimeImmutable $now, ?string $secretTip = null): array
    {
        $secret = $entry->isSecret();

        return [
            'id' => FilePath::join($folder, $entry->name),
            'name' => $entry->name.($entry->isDir() ? '/' : ''),
            'size' => match ($entry->type) {
                'dir' => $text('files.kinds.dir'),
                'link' => '→ '.($entry->target ?? $text('files.link')),
                'file' => Bytes::human($entry->size),
                default => $text('files.kinds.other'),
            },
            'changed' => self::when($entry->mtime, $now, $text),
            // A file with passwords stands out, and its row offers no download: it is downloaded from its own page, asked first.
            'mark' => $secret ? ['label' => $text('files.secret'), 'text' => $text('files.secret'), 'status' => 'warning', 'tip' => $secretTip ?? $text('files.secret_tip')] : '',
            // A link is shown, never followed: nothing opens it.
            '_actions' => $actions,
        ];
    }

    /**
     * «удалён 2 часа назад» or «заменён 2 часа назад» (simai-env 1.64.0); just when from an older server.
     *
     * @param array{label: string, text: string, tip?: string}|string $when
     * @param callable(string, array<string, string>=): string $text
     * @return array<string, string>|string
     */
    private static function why(?string $reason, array|string $when, callable $text): array|string
    {
        if ($reason === null) {
            return $when;
        }
        $why = $text('files.trash.reasons.'.$reason);
        if (is_string($when)) {
            return $why;
        }

        return ['label' => $why.' '.$when['label'], 'text' => $why.' '.$when['text']] + (isset($when['tip']) ? ['tip' => $when['tip']] : []);
    }

    /**
     * «2 ч назад», the moment in the reader's words in the tooltip (C183); nothing when the server gave no time.
     *
     * @param callable(string, array<string, string>=): string $text
     * @return array{label: string, text: string, tip?: string}|string
     */
    private static function when(?string $moment, \DateTimeImmutable $now, callable $text): array|string
    {
        $ago = Moment::ago($moment, $now, $text);

        return $ago === null ? '' : ['label' => $ago, 'text' => $ago, 'tip' => Moment::human($moment)];
    }
}
