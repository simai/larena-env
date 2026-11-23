<?php

declare(strict_types=1);

namespace Larena\Env\Navigation;

use Larena\Admin\Contracts\AdminNavigationContributor;
use Larena\Admin\Navigation\AdminNavigationDescriptor;

final class ServerAdminNavigationContributor implements AdminNavigationContributor
{
    /** Each row of a screen a newer simai-env brings, by the command it needs; ListedCommands reads each of them. */
    public const array NEEDS = ['env.services' => 'service:list', 'env.software' => 'software:list', 'env.software.php' => 'php:list', 'env.firewall' => 'firewall:status', 'env.security' => 'security:status', 'env.storages' => 'backup:storage-list', 'env.panel.access' => 'panel:access', 'env.update' => 'self:update-plan'];

    /** The rows of a feature the product may leave off (auditor msg-3d61bd36, point 5), by the feature. */
    public const array FEATURES = ['env.dns' => 'env.dns', 'env.dns.connection' => 'env.dns'];

    /**
     * The rows of screens that only a newer simai-env can use show only where the server lists their commands; without
     * a way to ask, they do not show. A row of a feature that is off does not show either.
     *
     * @param (\Closure(string): bool)|null $listed
     * @param (\Closure(string): bool)|null $featureOn null: every feature is on
     */
    public function __construct(private ?\Closure $listed = null, private ?\Closure $featureOn = null)
    {
    }

    public function ownerPackage(): string
    {
        return 'larena/env';
    }

    public function navigationDescriptors(): array
    {
        // The sites, at either level; the server's overview and the people's spaces, at the system level (owner decision 2026-10-31).
        // The sites' row matches every panel route; the row that names a page most closely marks it (larena/admin
        // AdminNavigationDescriptor::matchStrength), so the overview and the spaces name their own page.
        $rows = [new AdminNavigationDescriptor(
            id: 'env.people',
            ownerPackage: $this->ownerPackage(),
            label: 'People',
            routeName: 'larena.env.admin.people',
            routeUri: '/admin/server/people',
            category: 'operations',
            state: 'operator_slice',
            // The system level gives people their spaces (Panel plan 2.4).
            accessScope: 'env.server.read',
            auditEvent: 'env.people.viewed',
            statusCap: 'env_panel_skeleton',
            order: 3,
            group: 'operations',
            knownLimitations: ['not_production_ready'],
            surface: 'product',
            labelKey: 'larena-env::admin.people.navigation',
            activeRoutePattern: 'larena.env.admin.people*',
            icon: 'group',
        ), new AdminNavigationDescriptor(
            id: 'env.server.overview',
            ownerPackage: $this->ownerPackage(),
            label: 'Overview',
            routeName: 'larena.env.admin.overview',
            routeUri: '/admin/server/overview',
            category: 'operations',
            state: 'operator_slice',
            accessScope: 'env.server.read',
            auditEvent: 'env.server.overview_viewed',
            statusCap: 'env_panel_skeleton',
            // The panel's dashboard comes first in the menu (the test server's owner could not find it, 2026-11-07).
            order: 1,
            group: 'operations',
            knownLimitations: ['not_production_ready'],
            surface: 'product',
            labelKey: 'larena-env::admin.overview.navigation',
            activeRoutePattern: 'larena.env.admin.overview*',
            icon: 'monitor_heart',
        ), new AdminNavigationDescriptor(
            // «Настройки»: the map of the panel's and the server's settings with their values, the menu's first row (study
            // part 8, PX-81).
            id: 'env.settings',
            ownerPackage: $this->ownerPackage(),
            label: 'All settings',
            routeName: 'larena.env.admin.settings',
            routeUri: '/admin/server/settings',
            category: 'operations',
            state: 'operator_slice',
            accessScope: 'env.server.read',
            auditEvent: 'env.settings.viewed',
            statusCap: 'env_panel_skeleton',
            order: 2,
            group: 'operations',
            knownLimitations: ['not_production_ready'],
            surface: 'product',
            labelKey: 'larena-env::admin.panel_settings.navigation',
            activeRoutePattern: 'larena.env.admin.settings',
            icon: 'tune',
        ), new AdminNavigationDescriptor(
            // DNS through the provider's API (Panel plan, stage 7): the zones, and the connection among the panel's settings.
            id: 'env.dns',
            ownerPackage: $this->ownerPackage(),
            label: 'DNS',
            routeName: 'larena.env.admin.dns',
            routeUri: '/admin/server/dns',
            category: 'operations',
            state: 'operator_slice',
            accessScope: 'env.dns.read',
            auditEvent: 'env.dns.viewed',
            statusCap: 'env_panel_skeleton',
            order: 6,
            group: 'operations',
            knownLimitations: ['not_production_ready'],
            surface: 'product',
            labelKey: 'larena-env::admin.dns.navigation',
            activeRoutePattern: 'larena.env.admin.dns*',
            icon: 'web_asset',
        ), new AdminNavigationDescriptor(
            id: 'env.dns.connection',
            ownerPackage: $this->ownerPackage(),
            label: 'DNS',
            routeName: 'larena.env.admin.dns.connection',
            routeUri: '/admin/server/dns/connection',
            category: 'operations',
            state: 'operator_slice',
            accessScope: 'env.dns.read',
            auditEvent: 'env.dns.connection_viewed',
            statusCap: 'env_panel_skeleton',
            order: 7,
            group: 'operations',
            knownLimitations: ['not_production_ready'],
            surface: 'product',
            labelKey: 'larena-env::admin.dns.connection_navigation',
            activeRoutePattern: 'larena.env.admin.dns.connection*',
            icon: 'key',
        ), new AdminNavigationDescriptor(
            // Where the server sends its own alerts (simai-env 1.31.0), among the panel's settings.
            id: 'env.alerts.channel',
            ownerPackage: $this->ownerPackage(),
            label: 'Alerts',
            routeName: 'larena.env.admin.alerts.channel',
            routeUri: '/admin/server/alerts/channel',
            category: 'operations',
            state: 'operator_slice',
            accessScope: 'env.server.read',
            auditEvent: 'env.alerts.channel_viewed',
            statusCap: 'env_panel_skeleton',
            order: 8,
            group: 'operations',
            knownLimitations: ['not_production_ready'],
            surface: 'product',
            labelKey: 'larena-env::admin.alerts.navigation',
            activeRoutePattern: 'larena.env.admin.alerts.channel*',
            icon: 'notifications_active',
        ), new AdminNavigationDescriptor(
            // The panel's own mail (owner decision C335), among the panel's settings: under the settings' address.
            id: 'env.mail',
            ownerPackage: $this->ownerPackage(),
            label: 'Mail',
            routeName: 'larena.env.admin.settings.mail',
            routeUri: '/admin/server/settings/mail',
            category: 'operations',
            state: 'operator_slice',
            accessScope: 'env.server.read',
            auditEvent: 'env.mail.viewed',
            statusCap: 'env_panel_skeleton',
            order: 9,
            group: 'operations',
            knownLimitations: ['not_production_ready'],
            surface: 'product',
            labelKey: 'larena-env::mail.navigation',
            activeRoutePattern: 'larena.env.admin.settings.mail*',
            icon: 'mail',
        ), new AdminNavigationDescriptor(
            // The server's services, restarted or reloaded by plan (Panel plan 5.1).
            id: 'env.services',
            ownerPackage: $this->ownerPackage(),
            label: 'Services',
            routeName: 'larena.env.admin.services',
            routeUri: '/admin/server/services',
            category: 'operations',
            state: 'operator_slice',
            accessScope: 'env.server.read',
            auditEvent: 'env.services.viewed',
            statusCap: 'env_panel_skeleton',
            order: 10,
            group: 'operations',
            knownLimitations: ['not_production_ready'],
            surface: 'product',
            labelKey: 'larena-env::admin.services.navigation',
            activeRoutePattern: 'larena.env.admin.services*',
            icon: 'settings_applications',
        ), new AdminNavigationDescriptor(
            // The server's software and the system's updates (simai-env 1.38.0).
            id: 'env.software',
            ownerPackage: $this->ownerPackage(),
            label: 'Программы',
            routeName: 'larena.env.admin.software',
            routeUri: '/admin/server/software',
            category: 'operations',
            state: 'operator_slice',
            accessScope: 'env.server.read',
            auditEvent: 'env.software.viewed',
            statusCap: 'env_panel_skeleton',
            order: 12,
            group: 'operations',
            knownLimitations: ['not_production_ready'],
            surface: 'product',
            labelKey: 'larena-env::admin.software.overview.navigation',
            activeRoutePattern: 'larena.env.admin.software',
            icon: 'deployed_code',
        ), new AdminNavigationDescriptor(
            // The server's PHP versions: installed, updated, removed, their extensions (simai-env 1.37.0).
            id: 'env.software.php',
            ownerPackage: $this->ownerPackage(),
            label: 'PHP',
            routeName: 'larena.env.admin.software.php',
            routeUri: '/admin/server/software/php',
            category: 'operations',
            state: 'operator_slice',
            accessScope: 'env.server.read',
            auditEvent: 'env.software_php.viewed',
            statusCap: 'env_panel_skeleton',
            order: 13,
            group: 'operations',
            knownLimitations: ['not_production_ready'],
            surface: 'product',
            labelKey: 'larena-env::admin.software.php.navigation',
            activeRoutePattern: 'larena.env.admin.software.php*',
            icon: 'code_blocks',
        ), new AdminNavigationDescriptor(
            // The firewall, each change undone by the server unless kept in time (Panel plan 5.2).
            id: 'env.firewall',
            ownerPackage: $this->ownerPackage(),
            label: 'Firewall',
            routeName: 'larena.env.admin.firewall',
            routeUri: '/admin/server/firewall',
            category: 'operations',
            state: 'operator_slice',
            accessScope: 'env.server.read',
            auditEvent: 'env.firewall.viewed',
            statusCap: 'env_panel_skeleton',
            order: 11,
            group: 'operations',
            knownLimitations: ['not_production_ready'],
            surface: 'product',
            labelKey: 'larena-env::admin.firewall.navigation',
            activeRoutePattern: 'larena.env.admin.firewall*',
            icon: 'shield_person',
        ), new AdminNavigationDescriptor(
            // The server's security: score, problems with their fixes, the level, the files (simai-env 1.45.0–1.47.0).
            id: 'env.security',
            ownerPackage: $this->ownerPackage(),
            label: 'Security',
            routeName: 'larena.env.admin.security',
            routeUri: '/admin/server/security',
            category: 'operations',
            state: 'operator_slice',
            accessScope: 'env.server.read',
            auditEvent: 'env.security.viewed',
            statusCap: 'env_panel_skeleton',
            order: 10,
            group: 'operations',
            knownLimitations: ['not_production_ready'],
            surface: 'product',
            labelKey: 'larena-env::admin.security.navigation',
            activeRoutePattern: 'larena.env.admin.security*',
            icon: 'verified_user',
        ), new AdminNavigationDescriptor(
            // Storages for the sites' backups away from the server and their copies (simai-env 1.44.0).
            id: 'env.storages',
            ownerPackage: $this->ownerPackage(),
            label: 'Backup storages',
            routeName: 'larena.env.admin.storages',
            routeUri: '/admin/server/backups',
            category: 'operations',
            state: 'operator_slice',
            accessScope: 'env.server.read',
            auditEvent: 'env.storages.viewed',
            statusCap: 'env_panel_skeleton',
            order: 12,
            group: 'operations',
            knownLimitations: ['not_production_ready'],
            surface: 'product',
            labelKey: 'larena-env::admin.storages.navigation',
            activeRoutePattern: 'larena.env.admin.storages*',
            icon: 'cloud_upload',
        ), new AdminNavigationDescriptor(
            // Who reaches the panel, among the panel's settings (Panel plan 5.3).
            id: 'env.panel.access',
            ownerPackage: $this->ownerPackage(),
            label: 'Panel access',
            routeName: 'larena.env.admin.panel.access',
            routeUri: '/admin/server/panel-access',
            category: 'operations',
            state: 'operator_slice',
            accessScope: 'env.server.read',
            auditEvent: 'env.panel_access.viewed',
            statusCap: 'env_panel_skeleton',
            order: 12,
            group: 'operations',
            knownLimitations: ['not_production_ready'],
            surface: 'product',
            labelKey: 'larena-env::admin.panel_access.navigation',
            activeRoutePattern: 'larena.env.admin.panel.access*',
            icon: 'lock',
        ), new AdminNavigationDescriptor(
            // «Обновления» among the panel's settings (owner 2026-11-18): simai-env and the panel, their versions always,
            // «Проверить» and «Обновить» through the server's plan (simai-env 1.32.0, 1.60.0).
            id: 'env.update',
            ownerPackage: $this->ownerPackage(),
            label: 'Updates',
            routeName: 'larena.env.admin.update',
            routeUri: '/admin/server/update',
            category: 'operations',
            state: 'operator_slice',
            accessScope: 'env.server.read',
            auditEvent: 'env.update.viewed',
            statusCap: 'env_panel_skeleton',
            order: 13,
            group: 'operations',
            knownLimitations: ['not_production_ready'],
            surface: 'product',
            labelKey: 'larena-env::admin.update.navigation',
            activeRoutePattern: 'larena.env.admin.update*',
            icon: 'system_update',
        ), new AdminNavigationDescriptor(
            // «Базы данных» (simai-env 1.59.0 db:list): both levels, an owner's own sites' databases only.
            id: 'env.databases',
            ownerPackage: $this->ownerPackage(),
            label: 'Databases',
            routeName: 'larena.env.admin.databases',
            routeUri: '/admin/server/databases',
            category: 'operations',
            state: 'operator_slice',
            accessScope: 'env.sites.read',
            auditEvent: 'env.databases.viewed',
            statusCap: 'env_panel_skeleton',
            order: 6,
            group: 'operations',
            knownLimitations: ['not_production_ready'],
            surface: 'product',
            labelKey: 'larena-env::admin.databases.navigation',
            activeRoutePattern: 'larena.env.admin.databases*',
            icon: 'database',
        ), new AdminNavigationDescriptor(
            id: 'env.server',
            ownerPackage: $this->ownerPackage(),
            label: 'Sites',
            routeName: 'larena.env.admin.index',
            routeUri: '/admin/server',
            category: 'operations',
            state: 'operator_slice',
            accessScope: 'env.sites.read',
            auditEvent: 'env.server.viewed',
            statusCap: 'env_panel_skeleton',
            order: 5,
            group: 'operations',
            knownLimitations: ['not_production_ready', 'frontend_not_complete'],
            surface: 'product',
            labelKey: 'larena-env::admin.navigation',
            activeRoutePattern: 'larena.env.admin.*',
            icon: 'hard_drive',
        )];
        // Each row of a screen a newer simai-env brings, by the command it needs (Panel plan stage 5).
        $needs = self::NEEDS;
        $features = self::FEATURES;

        return array_values(array_filter($rows, fn (AdminNavigationDescriptor $row): bool => (!isset($needs[$row->id])
            || ($this->listed !== null && ($this->listed)($needs[$row->id])))
            && (!isset($features[$row->id]) || $this->featureOn === null || ($this->featureOn)($features[$row->id]))));
    }
}
