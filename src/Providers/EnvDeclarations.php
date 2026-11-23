<?php

declare(strict_types=1);

namespace Larena\Env\Providers;

use Larena\Access\ValueObjects\AccessOperationDescriptor;

/**
 * The one source of the package's rights and audit events (auditor msg-3d61bd36, point 3). The provider registers the
 * rights from it, PanelAudit refuses to write an event it does not name, and `composer run declarations:check` (part of
 * quality:gate) fails when access.yaml, audit.yaml or module.yaml say anything else, or when the code registers or
 * writes a right or an event that is not here: the navigation's rights and view events, the routes' `access:` rights,
 * every right a screen authorizes and every event the code writes.
 */
final class EnvDeclarations
{
    /**
     * Reading the server is one right; changing it is another, and the screens ask for them apart. The sites a person
     * reaches at either level (owner decision 2026-10-31): the whole server's, or an owner's, which the gateway narrows to.
     * DNS through the provider's API (Panel plan, stage 7): the zones and the token are the whole server's; a person of the
     * user level points their own site's domain through env.sites.operate.
     *
     * @var array<string, array{label: string, target: string, grant: string, risk: string, audit: string}>
     */
    public const array RIGHTS = [
        'env.server.read' => ['label' => 'larena-env::operations.server_read', 'target' => 'env.server:all', 'grant' => 'read', 'risk' => 'normal', 'audit' => 'denial'],
        'env.server.operate' => ['label' => 'larena-env::operations.server_operate', 'target' => 'env.server:all', 'grant' => 'write', 'risk' => 'high', 'audit' => 'denial'],
        'env.sites.read' => ['label' => 'larena-env::operations.sites_read', 'target' => 'env.sites:own', 'grant' => 'read', 'risk' => 'normal', 'audit' => 'denial'],
        'env.sites.operate' => ['label' => 'larena-env::operations.sites_operate', 'target' => 'env.sites:own', 'grant' => 'write', 'risk' => 'high', 'audit' => 'denial'],
        'env.dns.read' => ['label' => 'larena-env::operations.dns_read', 'target' => 'env.dns:all', 'grant' => 'read', 'risk' => 'normal', 'audit' => 'denial'],
        'env.dns.operate' => ['label' => 'larena-env::operations.dns_operate', 'target' => 'env.dns:all', 'grant' => 'write', 'risk' => 'high', 'audit' => 'denial'],
    ];

    /**
     * The events the panel writes itself (PanelAudit), by type and severity.
     *
     * @var array<string, string>
     */
    public const array AUDIT_EVENTS = [
        'env.person.owner_assigned' => 'notice',
        'env.person.owner_released' => 'notice',
        'env.person.invited' => 'notice',
        'env.person.blocked' => 'notice',
        'env.person.unblocked' => 'notice',
        'env.person.removed' => 'notice',
        'env.acting_as.started' => 'notice',
        'env.acting_as.stopped' => 'notice',
        'env.person.secret_files_allowed' => 'notice',
        'env.person.secret_files_disallowed' => 'notice',
        'env.secret_file.opened' => 'notice',
        'env.secret_file.downloaded' => 'notice',
        'env.secret_file.saved' => 'notice',
        'env.dns.connected' => 'notice',
        'env.dns.disconnected' => 'notice',
        'env.dns.record_changed' => 'notice',
        'env.mail.changed' => 'notice',
        'env.mail.checked' => 'notice',
        'env.mail.switched_off' => 'notice',
        'env.mail.password_lost' => 'notice',
        'env.person.password_reset_console' => 'notice',
        'env.server.change_started' => 'notice',
    ];

    /**
     * The view events the panel's menu rows name (ServerAdminNavigationContributor, `auditEvent`), declared as Auth declares
     * its own; the admin's navigation carries them, the panel does not write them.
     *
     * @var array<string, string>
     */
    public const array VIEW_EVENTS = [
        'env.people.viewed' => 'info',
        'env.server.overview_viewed' => 'info',
        'env.settings.viewed' => 'info',
        'env.dns.viewed' => 'info',
        'env.dns.connection_viewed' => 'info',
        'env.alerts.channel_viewed' => 'info',
        'env.mail.viewed' => 'info',
        'env.services.viewed' => 'info',
        'env.software.viewed' => 'info',
        'env.software_php.viewed' => 'info',
        'env.firewall.viewed' => 'info',
        'env.security.viewed' => 'info',
        'env.storages.viewed' => 'info',
        'env.panel_access.viewed' => 'info',
        'env.update.viewed' => 'info',
        'env.databases.viewed' => 'info',
        'env.server.viewed' => 'info',
    ];

    private function __construct()
    {
    }

    /** @return list<AccessOperationDescriptor> the rights as Access registers them */
    public static function descriptors(): array
    {
        $descriptors = [];
        foreach (self::RIGHTS as $code => $right) {
            $descriptors[] = new AccessOperationDescriptor(
                code: $code,
                ownerPackage: 'larena/env',
                labelKey: $right['label'],
                target: $right['target'],
                requiredGrant: $right['grant'],
                risk: $right['risk'],
                auditDenials: self::auditsDenials($right['audit']),
            );
        }

        return $descriptors;
    }

    /** Whether Access audits a refusal of a right declared with this audit mode (access.yaml `audit`). */
    public static function auditsDenials(string $mode): bool
    {
        return in_array($mode, ['denial', 'always'], true);
    }

    /** Whether the panel may write an event of this type. */
    public static function writes(string $type): bool
    {
        return isset(self::AUDIT_EVENTS[$type]);
    }

    /** @return array<string, string> every declared event, written and viewed, by type and severity, as audit.yaml lists them */
    public static function events(): array
    {
        return self::AUDIT_EVENTS + self::VIEW_EVENTS;
    }
}
