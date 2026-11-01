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
