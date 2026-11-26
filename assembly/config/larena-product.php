<?php

declare(strict_types=1);

/*
 * The product this application is: Larena Panel (owner decision 2026-10-31). It chooses the features of its
 * packages and declares the roots of its menu; what is off has no routes, menu rows, operations or tables.
 * The sections are the Panel plan's (larena-specs docs/architecture/2026-10-31-panel-work-plan.md); a root whose
 * screen does not exist yet is not declared, so the menu has no dead links.
 */
return [
    // None of the admin's optional features: no CMS sites or site switcher, no settings overview, catalog or drafts,
    // no groups, no site pages, no roles editor (the panel's two levels are fixed).
    'features' => [],

    'menu' => [
        'roots' => [
            // Both levels: the sites a person reaches, the first screen. «История» (the server's runs and the action
            // journal, larena/admin) is the menu's last root of its own, by «Уведомления» (owner decision dec-d9f352569fd1).
            'sites' => ['label' => 'larena-env::admin.menu.sites', 'icon' => 'hard_drive', 'level' => 'site', 'kind' => 'row', 'row' => 'env.server'],
            // Both levels: the sites' databases (simai-env 1.59.0 db:list), each opening its site's «База данных».
            'databases' => ['label' => 'larena-env::admin.menu.databases', 'icon' => 'database', 'level' => 'site', 'kind' => 'row', 'row' => 'env.databases'],
            // The system level: people, the server, the panel's own settings. «Люди» is one screen (roles study part 7,
            // PX-69): the people with what each may do, the invitation and their server accounts.
            'users' => ['label' => 'larena-env::admin.menu.users', 'icon' => 'group', 'level' => 'install', 'kind' => 'row', 'row' => 'env.people'],
            'server' => ['label' => 'larena-env::admin.menu.server', 'icon' => 'memory', 'level' => 'install', 'kind' => 'root'],
            'settings' => ['label' => 'larena-env::admin.menu.settings', 'icon' => 'settings', 'level' => 'install', 'kind' => 'root'],
        ],
        'group_roots' => [],
        'row_roots' => [
            'env.server.overview' => 'server',
            // DNS through the provider's API (Panel plan, stage 7): the zones beside the server, the connection among the settings.
            'env.dns' => 'server',
            'env.dns.connection' => 'settings',
            // «Настройки» opens on its map of values (study part 8, PX-81): security and programs stay under «Сервер».
            'env.settings' => 'settings',
            // Where the server sends its own alerts (simai-env 1.31.0).
            'env.alerts.channel' => 'settings',
            // The panel's own mail (owner decision C335).
            'env.mail' => 'settings',
            // The services and the firewall beside the server, who reaches the panel among its settings (Panel plan 5.1–5.3).
            'env.services' => 'server',
            // The server's security (simai-env 1.45.0): score, problems with their fixes, level, files.
            'env.security' => 'server',
            'env.firewall' => 'server',
            // Storages for the sites' backups away from the server (simai-env 1.44.0).
            'env.storages' => 'server',
            // The server's software with simai-env's, the panel's and the system's updates (one place since the
            // panel's UX audit), and its PHP versions (simai-env 1.32.0–1.38.0).
            'env.software' => 'server',
            'env.software.php' => 'server',
            'env.panel.access' => 'settings',
            // simai-env's and the panel's updates among the panel's settings (owner 2026-11-18).
            'env.update' => 'settings',
            // «Целостность файлов» (larena/update's /admin/integrity) beside the panel's updates: the files it checks are the
            // ones an update replaces.
            'admin.integrity' => 'settings',
            'admin.settings_general' => 'settings',
            'admin.site_backgrounds' => 'settings',
        ],
        // The person's own account and its security, in the person's menu at both levels.
        'person_links' => ['auth.security'],
        // The panel's UX audit (owner decision 2026-11-05): each root is a short list without group headings; the
        // sign-in diagnostics are a report for developers (its address still opens); the backgrounds belong to the
        // sign-in page («Страница входа», the general settings page), which links to them and is marked on their page.
        'headings' => false,
        // The users' screen of the CMS is not the panel's (PX-69): «Люди» invites, blocks and changes people; its address
        // still opens, for a password reset.
        // larena/backup's own screen (/admin/backups) is the CMS's: the panel's backups are the server's, under «Сервер»; the
        // package serves the update's site copy through its larena:backup:* commands, and its address still opens.
        // «Возможности сайта» switches a CMS site's functions (owner 2026-11-25, variant A); the panel has none to switch.
        'hidden_rows' => ['auth.diagnostics', 'auth.users', 'backup.safety_center', 'admin.settings_capabilities', 'admin.licence', 'admin.updates', 'admin.addons'],
        'folded_rows' => ['admin.site_backgrounds' => 'admin.settings_general'],
        // The bell's panel links to where the server's alerts are sent («Куда слать оповещения», C384).
        'notifications_settings' => 'larena.env.admin.alerts.channel',
    ],
];
