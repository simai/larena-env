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
        // The sites, at either level, and the server's overview, at the system level (owner decision 2026-10-31).
        return [new AdminNavigationDescriptor(
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
            // Before the sites, whose pattern covers every panel page: the overview is matched as the current row first.
            order: 4,
            group: 'operations',
            knownLimitations: ['not_production_ready'],
            surface: 'product',
            labelKey: 'larena-env::admin.overview.navigation',
            activeRoutePattern: 'larena.env.admin.overview',
            icon: 'monitor_heart',
        )];
    }
}
