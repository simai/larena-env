<?php

declare(strict_types=1);

namespace Larena\Env\Navigation;

use Larena\Admin\Contracts\AdminNavigationContributor;
use Larena\Admin\Navigation\AdminNavigationDescriptor;

final class ServerAdminNavigationContributor implements AdminNavigationContributor
{
    /**
     * The rows of screens that only a newer simai-env can use show only where the server lists their commands; without
     * a way to ask, they do not show.
     *
     * @param (\Closure(string): bool)|null $listed
     */
    public function __construct(private ?\Closure $listed = null)
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
            label: 'Spaces',
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
            icon: 'workspaces',
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
            order: 4,
            group: 'operations',
            knownLimitations: ['not_production_ready'],
            surface: 'product',
            labelKey: 'larena-env::admin.overview.navigation',
            activeRoutePattern: 'larena.env.admin.overview',
            icon: 'monitor_heart',
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
            icon: 'language',
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
            // A safe update of simai-env and of the panel (simai-env 1.32.0), beside the server's overview.
            id: 'env.update',
            ownerPackage: $this->ownerPackage(),
            label: 'Update',
            routeName: 'larena.env.admin.update',
            routeUri: '/admin/server/update',
            category: 'operations',
            state: 'operator_slice',
            accessScope: 'env.server.read',
            auditEvent: 'env.update.viewed',
            statusCap: 'env_panel_skeleton',
            order: 9,
            group: 'operations',
            knownLimitations: ['not_production_ready'],
            surface: 'product',
            labelKey: 'larena-env::admin.update.navigation',
            activeRoutePattern: 'larena.env.admin.update*',
            icon: 'system_update',
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
            icon: 'restart_alt',
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
            icon: 'shield',
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
            icon: 'dns',
        )];
        // Each row of a screen a newer simai-env brings, by the command it needs (Panel plan stage 5).
        $needs = ['env.services' => 'service:list', 'env.firewall' => 'firewall:status', 'env.panel.access' => 'panel:access'];

        return array_values(array_filter($rows, fn (AdminNavigationDescriptor $row): bool => !isset($needs[$row->id])
            || ($this->listed !== null && ($this->listed)($needs[$row->id]))));
    }
}
