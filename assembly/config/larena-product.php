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
            // Both levels: the sites a person reaches, the first screen; the audit log.
            'sites' => ['label' => 'larena-env::admin.menu.sites', 'icon' => 'dns', 'level' => 'site', 'kind' => 'row', 'row' => 'env.server'],
            'journal' => ['label' => 'larena-env::admin.menu.journal', 'icon' => 'history', 'level' => 'site', 'kind' => 'row', 'row' => 'audit.history'],
            // The system level: people, the server, the panel's own settings.
            'users' => ['label' => 'larena-env::admin.menu.users', 'icon' => 'group', 'level' => 'install', 'kind' => 'root'],
            'server' => ['label' => 'larena-env::admin.menu.server', 'icon' => 'memory', 'level' => 'install', 'kind' => 'root'],
            'settings' => ['label' => 'larena-env::admin.menu.settings', 'icon' => 'settings', 'level' => 'install', 'kind' => 'root'],
        ],
        'group_roots' => [],
        'row_roots' => [
            // People and the spaces they work in (Panel plan 2.4): their roles and blocking, then their owners.
            'auth.users' => 'users',
            'env.people' => 'users',
            'env.server.overview' => 'server',
            // DNS through the provider's API (Panel plan, stage 7): the zones beside the server, the connection among the settings.
            'env.dns' => 'server',
            'env.dns.connection' => 'settings',
            // Where the server sends its own alerts (simai-env 1.31.0).
            'env.alerts.channel' => 'settings',
            // The services and the firewall beside the server, who reaches the panel among its settings (Panel plan 5.1–5.3).
            'env.services' => 'server',
            // The server's security (simai-env 1.45.0): score, problems with their fixes, level, files.
            'env.security' => 'server',
            'env.firewall' => 'server',
            // The server's software with simai-env's, the panel's and the system's updates (one place since the
            // panel's UX audit), and its PHP versions (simai-env 1.32.0–1.38.0).
            'env.software' => 'server',
            'env.software.php' => 'server',
            'env.panel.access' => 'settings',
            'admin.settings_general' => 'settings',
            'admin.site_backgrounds' => 'settings',
        ],
        // The person's own account and its security, in the person's menu at both levels.
        'person_links' => ['auth.security'],
        // The panel's UX audit (owner decision 2026-11-05): each root is a short list without group headings; the
        // sign-in diagnostics are a report for developers (its address still opens); the backgrounds belong to the
        // sign-in page («Страница входа», the general settings page), which links to them and is marked on their page.
        'headings' => false,
        'hidden_rows' => ['auth.diagnostics'],
        'folded_rows' => ['admin.site_backgrounds' => 'admin.settings_general'],
    ],
];
