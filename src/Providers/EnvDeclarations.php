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
        'env.server.job_cancelled' => 'notice',
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

    /**
     * The package's features (auditor msg-3d61bd36, point 5; core's FeatureSet, owner decision 2026-10-31): the product
     * chooses them in config/larena-product.php, and what is off is absent — its routes, menu rows, rights, tables and
     * background jobs. Local flags (larena-env.admin.enabled, larena-env.scheduler.enabled) can only narrow the choice.
     * Every right belongs to exactly one feature (declarations:check).
     *
     * - env.admin: the server's screens, their menu rows and the admin's alert bar, history, first steps and object menu.
     * - env.monitoring: the server's check every 5 minutes (larena:env-check) and the load it keeps; with env.admin also
     *   the pages' warm-up every minute (larena:env-warm).
     * - env.notifications: the check tells people by the bell and by mail; who is told is decided by env.admin's rights.
     * - env.dns: the DNS zones through the provider's API, their screens, rights and tables, a site's own DNS section.
     *
     * @var array<string, array{requires: list<string>, operations: list<string>}>
     */
    public const array FEATURES = [
        'env.admin' => ['requires' => [], 'operations' => ['env.server.read', 'env.server.operate', 'env.sites.read', 'env.sites.operate']],
        'env.monitoring' => ['requires' => [], 'operations' => []],
        'env.notifications' => ['requires' => ['env.monitoring', 'env.admin'], 'operations' => []],
        'env.dns' => ['requires' => ['env.admin'], 'operations' => ['env.dns.read', 'env.dns.operate']],
    ];

    private function __construct()
    {
    }

    /**
     * The rights as Access registers them: every one, or only those of the features that are on.
     *
     * @param (\Closure(string): bool)|null $featureOn
     *
     * @return list<AccessOperationDescriptor>
     */
    public static function descriptors(?\Closure $featureOn = null): array
    {
        $descriptors = [];
        foreach (self::RIGHTS as $code => $right) {
            if ($featureOn !== null && !$featureOn(self::featureOf($code))) {
                continue;
            }
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

    /** The feature a right belongs to; a right no feature names belongs to env.admin (declarations:check refuses one). */
    public static function featureOf(string $right): string
    {
        foreach (self::FEATURES as $feature => $declared) {
            if (in_array($right, $declared['operations'], true)) {
                return $feature;
            }
        }

        return 'env.admin';
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
