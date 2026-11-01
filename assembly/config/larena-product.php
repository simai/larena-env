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
            // Sign-in diagnostics under its own name, beside the server's overview.
            'auth.diagnostics' => 'server',
            'admin.settings_general' => 'settings',
            'admin.site_backgrounds' => 'settings',
        ],
        // The person's own account and its security, in the person's menu at both levels.
        'person_links' => ['auth.security'],
    ],
];
