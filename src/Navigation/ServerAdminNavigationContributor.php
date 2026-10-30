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
        return [new AdminNavigationDescriptor(
            id: 'env.server',
            ownerPackage: $this->ownerPackage(),
            label: 'Server',
            routeName: 'larena.env.admin.index',
            routeUri: '/admin/server',
            category: 'operations',
            state: 'operator_slice',
            accessScope: 'env.server.read',
            auditEvent: 'env.server.viewed',
            statusCap: 'env_panel_skeleton',
            order: 5,
            group: 'operations',
            knownLimitations: ['not_production_ready', 'frontend_not_complete'],
            surface: 'product',
            labelKey: 'larena-env::admin.navigation',
            activeRoutePattern: 'larena.env.admin.*',
            icon: 'dashboard',
        )];
    }
}
