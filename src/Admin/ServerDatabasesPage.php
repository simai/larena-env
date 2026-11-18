<?php

declare(strict_types=1);

namespace Larena\Env\Admin;

use Larena\Admin\Runtime\AdminDataviewPage;
use Larena\Env\Server\Bytes;
use Larena\Env\Server\SiteDatabase;

/**
 * «Базы данных» (simai-env 1.59.0 db:list, simai-databases/1): the sites' databases as the admin's shared data view, rows
 * in place. Each site has one database at most; its row names it over its site, says its kind, its size and its users,
 * and opens the site's card on «База данных», where its connection details, password and browser are. An owner sees
 * their own sites' databases only (the server's scope). A database no site owns has no card to open: it is not listed.
 */
final class ServerDatabasesPage
{
    /** The kinds of database the server makes, as the new-site screen names them. */
    private const array ENGINES = ['mysql', 'mariadb', 'postgresql'];

    private function __construct()
    {
    }

    /**
     * @param list<SiteDatabase> $databases
     * @param callable(string, array<string, string>=): string $text
     */
    public static function of(array $databases, callable $text): AdminDataviewPage
    {
        $owned = array_values(array_filter($databases, static fn (SiteDatabase $database): bool => $database->site !== null && $database->site !== ''));
        usort($owned, static fn (SiteDatabase $a, SiteDatabase $b): int => strnatcasecmp((string) $a->site, (string) $b->site));
        $engines = array_values(array_unique([...self::ENGINES, ...array_map(static fn (SiteDatabase $database): string => $database->engine, $owned)]));
        $rows = array_map(static fn (SiteDatabase $database): array => [
            'id' => (string) $database->site,
            'name' => $database->name,
            'site' => (string) $database->site,
            'engine' => $database->engine,
            'size' => $database->sizeBytes === null ? '' : Bytes::human($database->sizeBytes),
            'users' => implode(', ', $database->users),
        ], $owned);

        return new AdminDataviewPage(
            instanceId: 'env-server-databases',
            title: $text('databases.title'),
            columns: [
                ['key' => 'name', 'label' => $text('databases.name'), 'role' => 'title', 'list' => 'title'],
                ['key' => 'site', 'label' => $text('databases.site'), 'list' => 'supporting'],
                ['key' => 'engine', 'label' => $text('databases.engine'), 'list' => 'supporting', 'type' => 'choice',
                    'options' => array_map(static fn (string $engine): array => ['value' => $engine, 'label' => self::engine($engine, $text)], $engines)],
                ['key' => 'size', 'label' => $text('databases.size'), 'list' => 'fact'],
                ['key' => 'users', 'label' => $text('databases.users'), 'list' => 'fact'],
            ],
            total: count($rows),
            pageId: 'page.admin.env-server',
            rows: $rows,
            perPage: 100,
            // A row opens its site's card on «База данных».
            rowLinks: ['view' => '/admin/server/sites/__ID__?tab=database'],
            countLabel: $text('databases.count'),
            tableViewLabel: $text('sites.table_view'),
            layout: 'list',
            emptyText: $text('databases.empty'),
            emptyDescription: $text('databases.empty_description'),
        );
    }

    /** @param callable(string, array<string, string>=): string $text */
    private static function engine(string $engine, callable $text): string
    {
        $word = $text('new_site.engines.'.$engine);

        return str_contains($word, 'new_site.engines.') ? $engine : $word;
    }
}
