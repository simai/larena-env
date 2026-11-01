<?php

declare(strict_types=1);

namespace Larena\Env\Navigation;

use Larena\Admin\Contracts\AdminNavigationContributor;
use Larena\Admin\Navigation\AdminNavigationDescriptor;

final class ServerAdminNavigationContributor implements AdminNavigationContributor
{
    public function ownerPackage(): string
    {
        return 'larena/env';
    }

    public function navigationDescriptors(): array
    {
        // The sites, at either level; the server's overview and the people's spaces, at the system level (owner decision 2026-10-31).
        // The sites' row matches every panel route; the row that names a page most closely marks it (larena/admin
        // AdminNavigationDescriptor::matchStrength), so the overview and the spaces name their own page.
        return [new AdminNavigationDescriptor(
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
    }
}
