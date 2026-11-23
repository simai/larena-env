<?php

declare(strict_types=1);

namespace Larena\Env\Operations;

use Larena\Env\Gateway\PanelCommands;
use Larena\Env\Providers\EnvDeclarations;

/**
 * The panel's changes as Core operations (auditor msg-3d61bd36, point 4; plan accepted in msg-3ee29ace). One row per
 * operation: the Larena name is what rights, the audit payload, receipts, «История» and any API or MCP client name; the
 * simai-env command is only how the gateway handler carries it out (`command`). Several operations may map to one
 * command (a certificate renewed from a site's screen or as a security fix, a site made again from a storage's copy);
 * one operation never maps to two commands. DNS through the provider's API has no command (`handler: dns`).
 *
 * - right: the operation's access scope, one of the package's rights (variant B, 16 rights by group). Each operation's
 *   right is in the family of the right whose routes reach it today, so moving the grants one to one changes nobody's
 *   reach (env.sites.operate's holders get every env.sites.* right, env.server.operate's every env.server.* right).
 * - reversible: `true` (undone by its inverse, or a copy is kept), `false` (cannot be undone) or `timed` (undone by
 *   itself unless kept, or undoable for a limited time). Core's declaration takes a boolean: `timed` counts as reversible.
 *   ENV's answers (msg-18b666fb): backup:storage-remove takes only the storage's settings and keys off the server, the
 *   copies stay in the bucket, and backup:storage-add with the same bucket and keys undoes it (the secret entered again):
 *   `true`. self:update keeps the previous code, but the gateway has no way back; root can pin an older tag by hand and
 *   the host's migrations are not undone: `false` for the panel. Since simai-env 1.65.3 *:restore-ready and
 *   backup:data-restore first take a held backup of the site, kept 7 days (`last-backup`, default yes, which the panel
 *   does not send): `timed`. A restore asked with `last-backup: no` would be `false`; the panel never asks it.
 * - risk is `external` for every one: the effect is outside Larena's database, and Core then always requires the plan
 *   a person approved.
 * - audit: `env.server.change_started` for every gateway change, with the operation and its reversibility in the payload;
 *   `env.server.job_cancelled` for a job stopped; DNS keeps its own events.
 *
 * Reads are not here: they stay typed readers. `file:download` streams bytes and stays outside Core.
 */
final class EnvOperationCatalog
{
    public const string PACKAGE = 'larena/env';
    public const string GATEWAY_HANDLER = 'env.handler.gateway';
    public const string DNS_HANDLER = 'env.handler.dns';

    public const string REVERSIBLE = 'true';
    public const string IRREVERSIBLE = 'false';
    public const string TIMED = 'timed';

    /**
     * @var array<string, array{command: ?string, right: string, reversible: string, feature?: string}>
     */
    public const array OPERATIONS = [
        // Sites (env.sites.operate): what a site's own screen does, at either level.
        'env.site.add' => ['command' => 'site:add', 'right' => 'env.sites.operate', 'reversible' => self::REVERSIBLE],
        'env.site.suspend' => ['command' => 'site:suspend', 'right' => 'env.sites.operate', 'reversible' => self::REVERSIBLE],
        'env.site.resume' => ['command' => 'site:resume', 'right' => 'env.sites.operate', 'reversible' => self::REVERSIBLE],
        'env.site.set_php' => ['command' => 'site:set-php', 'right' => 'env.sites.operate', 'reversible' => self::REVERSIBLE],
        'env.site.apache' => ['command' => 'site:apache', 'right' => 'env.sites.operate', 'reversible' => self::REVERSIBLE],
        'env.site.cache_clear' => ['command' => 'site:cache-clear', 'right' => 'env.sites.operate', 'reversible' => self::REVERSIBLE],
        'env.site.service_restart' => ['command' => 'site:service-restart', 'right' => 'env.sites.operate', 'reversible' => self::REVERSIBLE],
        'env.site.check_fix' => ['command' => 'site:check-fix', 'right' => 'env.sites.operate', 'reversible' => self::REVERSIBLE],
        'env.site.transfer' => ['command' => 'site:transfer', 'right' => 'env.sites.operate', 'reversible' => self::REVERSIBLE],
        'env.site.name_add' => ['command' => 'site:name-add', 'right' => 'env.sites.operate', 'reversible' => self::REVERSIBLE],
        'env.site.name_remove' => ['command' => 'site:name-remove', 'right' => 'env.sites.operate', 'reversible' => self::REVERSIBLE],
        'env.site.redirect_add' => ['command' => 'site:redirect-add', 'right' => 'env.sites.operate', 'reversible' => self::REVERSIBLE],
        'env.site.redirect_remove' => ['command' => 'site:redirect-remove', 'right' => 'env.sites.operate', 'reversible' => self::REVERSIBLE],
        'env.site.php_ini_set' => ['command' => 'site:php-ini-set', 'right' => 'env.sites.operate', 'reversible' => self::REVERSIBLE],
        'env.site.php_ini_unset' => ['command' => 'site:php-ini-unset', 'right' => 'env.sites.operate', 'reversible' => self::REVERSIBLE],
        'env.site.preview_link' => ['command' => 'site:preview-link', 'right' => 'env.sites.operate', 'reversible' => self::TIMED],
        'env.site.preview_link_close' => ['command' => 'site:preview-link-close', 'right' => 'env.sites.operate', 'reversible' => self::REVERSIBLE],
        'env.queue.restart' => ['command' => 'queue:restart', 'right' => 'env.sites.operate', 'reversible' => self::REVERSIBLE],
        'env.certificate.issue' => ['command' => 'ssl:letsencrypt', 'right' => 'env.sites.operate', 'reversible' => self::REVERSIBLE],
        'env.certificate.renew' => ['command' => 'ssl:renew', 'right' => 'env.sites.operate', 'reversible' => self::REVERSIBLE],
        'env.certificate.remove' => ['command' => 'ssl:remove', 'right' => 'env.sites.operate', 'reversible' => self::REVERSIBLE],
        'env.cron.job_add' => ['command' => 'cron:job-add', 'right' => 'env.sites.operate', 'reversible' => self::REVERSIBLE],
        'env.cron.job_remove' => ['command' => 'cron:job-remove', 'right' => 'env.sites.operate', 'reversible' => self::REVERSIBLE],
        'env.cron.job_enable' => ['command' => 'cron:job-enable', 'right' => 'env.sites.operate', 'reversible' => self::REVERSIBLE],
        'env.cron.job_disable' => ['command' => 'cron:job-disable', 'right' => 'env.sites.operate', 'reversible' => self::REVERSIBLE],
        'env.alerts.ack' => ['command' => 'self:alerts-ack', 'right' => 'env.sites.operate', 'reversible' => self::REVERSIBLE],
        // A switched-off kind of site switched on from «Новый сайт» (the system level only, which the screen checks).
        'env.profile.enable' => ['command' => 'profile:enable', 'right' => 'env.sites.operate', 'reversible' => self::REVERSIBLE],
        // The stop of a started job: the right of the routes that offer it.
        'env.job.cancel' => ['command' => 'job:cancel', 'right' => 'env.sites.operate', 'reversible' => self::IRREVERSIBLE],

        // A site's files (env.sites.files): the files, and the SSH and SFTP access to them.
        'env.file.write' => ['command' => 'file:write', 'right' => 'env.sites.files', 'reversible' => self::REVERSIBLE],
        'env.file.upload' => ['command' => 'file:upload', 'right' => 'env.sites.files', 'reversible' => self::REVERSIBLE],
        'env.file.extract' => ['command' => 'file:extract', 'right' => 'env.sites.files', 'reversible' => self::REVERSIBLE],
        'env.file.remove' => ['command' => 'file:remove', 'right' => 'env.sites.files', 'reversible' => self::REVERSIBLE],
        'env.file.trash_restore' => ['command' => 'file:trash-restore', 'right' => 'env.sites.files', 'reversible' => self::REVERSIBLE],
        'env.file.mkdir' => ['command' => 'file:mkdir', 'right' => 'env.sites.files', 'reversible' => self::REVERSIBLE],
        'env.file.rename' => ['command' => 'file:rename', 'right' => 'env.sites.files', 'reversible' => self::REVERSIBLE],
        'env.file.copy' => ['command' => 'file:copy', 'right' => 'env.sites.files', 'reversible' => self::REVERSIBLE],
        'env.file.archive' => ['command' => 'file:archive', 'right' => 'env.sites.files', 'reversible' => self::REVERSIBLE],
        'env.ssh_access.create_project' => ['command' => 'access:create-project', 'right' => 'env.sites.files', 'reversible' => self::REVERSIBLE],
        'env.ssh_access.add_key' => ['command' => 'access:add-key', 'right' => 'env.sites.files', 'reversible' => self::REVERSIBLE],
        'env.ssh_access.disable' => ['command' => 'access:disable', 'right' => 'env.sites.files', 'reversible' => self::REVERSIBLE],
        'env.ssh_access.enable' => ['command' => 'access:enable', 'right' => 'env.sites.files', 'reversible' => self::REVERSIBLE],
        'env.ssh_access.remove' => ['command' => 'access:remove', 'right' => 'env.sites.files', 'reversible' => self::IRREVERSIBLE],

        // A site's database (env.sites.database).
        'env.site.db_create' => ['command' => 'site:db-create', 'right' => 'env.sites.database', 'reversible' => self::REVERSIBLE],
        'env.site.db_rotate' => ['command' => 'site:db-rotate', 'right' => 'env.sites.database', 'reversible' => self::IRREVERSIBLE],
        'env.site.db_credentials' => ['command' => 'site:db-credentials', 'right' => 'env.sites.database', 'reversible' => self::IRREVERSIBLE],
        'env.site.db_browser' => ['command' => 'site:db-browser', 'right' => 'env.sites.database', 'reversible' => self::TIMED],
        'env.site.db_browser_close' => ['command' => 'site:db-browser-close', 'right' => 'env.sites.database', 'reversible' => self::REVERSIBLE],

        // A site's backups and restores (env.sites.backup).
        'env.backup.make' => ['command' => 'backup:data', 'right' => 'env.sites.backup', 'reversible' => self::REVERSIBLE],
        'env.backup.schedule' => ['command' => 'backup:data-schedule', 'right' => 'env.sites.backup', 'reversible' => self::REVERSIBLE],
        'env.backup.verify' => ['command' => 'backup:data-verify', 'right' => 'env.sites.backup', 'reversible' => self::REVERSIBLE],
        'env.backup.restore' => ['command' => 'backup:data-restore', 'right' => 'env.sites.backup', 'reversible' => self::TIMED],
        'env.site.restore' => ['command' => 'site:restore-ready', 'right' => 'env.sites.backup', 'reversible' => self::TIMED],
        'env.site.wp_restore' => ['command' => 'wp:restore-ready', 'right' => 'env.sites.backup', 'reversible' => self::TIMED],
        'env.site.bitrix_restore' => ['command' => 'bitrix:restore-ready', 'right' => 'env.sites.backup', 'reversible' => self::TIMED],

        // Releases and the applications' installers (env.sites.deploy).
        'env.site.deploy' => ['command' => 'site:deploy', 'right' => 'env.sites.deploy', 'reversible' => self::REVERSIBLE],
        'env.site.deploy_rollback' => ['command' => 'site:deploy-rollback', 'right' => 'env.sites.deploy', 'reversible' => self::REVERSIBLE],
        'env.app.wp_install' => ['command' => 'wp:install', 'right' => 'env.sites.deploy', 'reversible' => self::IRREVERSIBLE],
        'env.app.wp_finalize' => ['command' => 'wp:finalize', 'right' => 'env.sites.deploy', 'reversible' => self::IRREVERSIBLE],
        'env.app.laravel_finalize' => ['command' => 'laravel:finalize', 'right' => 'env.sites.deploy', 'reversible' => self::IRREVERSIBLE],
        'env.app.bitrix_finalize' => ['command' => 'bitrix:finalize', 'right' => 'env.sites.deploy', 'reversible' => self::IRREVERSIBLE],

        // What removes a site's data (env.sites.remove): a copy is kept for 7 days, except the trash emptied.
        'env.site.remove' => ['command' => 'site:remove', 'right' => 'env.sites.remove', 'reversible' => self::REVERSIBLE],
        'env.site.db_drop' => ['command' => 'site:db-drop', 'right' => 'env.sites.remove', 'reversible' => self::REVERSIBLE],
        'env.file.trash_empty' => ['command' => 'file:trash-empty', 'right' => 'env.sites.remove', 'reversible' => self::IRREVERSIBLE],

        // The server (env.server.operate): its services, alerts, and a site's own nginx directives.
        'env.site.nginx_custom' => ['command' => 'site:nginx-custom', 'right' => 'env.server.operate', 'reversible' => self::TIMED],
        'env.service.restart' => ['command' => 'service:restart', 'right' => 'env.server.operate', 'reversible' => self::REVERSIBLE],
        'env.service.reload' => ['command' => 'service:reload', 'right' => 'env.server.operate', 'reversible' => self::REVERSIBLE],
        'env.service.stop' => ['command' => 'service:stop', 'right' => 'env.server.operate', 'reversible' => self::REVERSIBLE],
        'env.service.start' => ['command' => 'service:start', 'right' => 'env.server.operate', 'reversible' => self::REVERSIBLE],
        'env.alerts.channel' => ['command' => 'self:alerts-channel', 'right' => 'env.server.operate', 'reversible' => self::REVERSIBLE],
        'env.alerts.test' => ['command' => 'self:alerts-test', 'right' => 'env.server.operate', 'reversible' => self::REVERSIBLE],
        'env.server.firewall_ensure' => ['command' => 'self:firewall-ensure', 'right' => 'env.server.operate', 'reversible' => self::REVERSIBLE],

        // The server's software (env.server.software): an update offers no way down.
        'env.software.refresh' => ['command' => 'software:refresh', 'right' => 'env.server.software', 'reversible' => self::REVERSIBLE],
        'env.software.update' => ['command' => 'software:update', 'right' => 'env.server.software', 'reversible' => self::IRREVERSIBLE],
        'env.software.install' => ['command' => 'software:install', 'right' => 'env.server.software', 'reversible' => self::REVERSIBLE],
        'env.software.remove' => ['command' => 'software:remove', 'right' => 'env.server.software', 'reversible' => self::REVERSIBLE],
        'env.php.install' => ['command' => 'php:install', 'right' => 'env.server.software', 'reversible' => self::REVERSIBLE],
        'env.php.remove' => ['command' => 'php:remove', 'right' => 'env.server.software', 'reversible' => self::REVERSIBLE],
        'env.php.extension' => ['command' => 'php:extension', 'right' => 'env.server.software', 'reversible' => self::REVERSIBLE],
        'env.php.update' => ['command' => 'php:update', 'right' => 'env.server.software', 'reversible' => self::IRREVERSIBLE],
        'env.profile.disable' => ['command' => 'profile:disable', 'right' => 'env.server.software', 'reversible' => self::REVERSIBLE],

        // The server's security (env.server.security): the firewall's rules are timed until kept.
        'env.security.scan' => ['command' => 'security:scan', 'right' => 'env.server.security', 'reversible' => self::REVERSIBLE],
        'env.security.fix' => ['command' => 'security:fix', 'right' => 'env.server.security', 'reversible' => self::REVERSIBLE],
        'env.security.files_scan' => ['command' => 'security:files-scan', 'right' => 'env.server.security', 'reversible' => self::REVERSIBLE],
        'env.security.quarantine' => ['command' => 'security:quarantine', 'right' => 'env.server.security', 'reversible' => self::REVERSIBLE],
        'env.security.quarantine_restore' => ['command' => 'security:quarantine-restore', 'right' => 'env.server.security', 'reversible' => self::REVERSIBLE],
        'env.security.level' => ['command' => 'security:level', 'right' => 'env.server.security', 'reversible' => self::REVERSIBLE],
        'env.security.geo' => ['command' => 'security:geo', 'right' => 'env.server.security', 'reversible' => self::REVERSIBLE],
        'env.security.geo_update' => ['command' => 'security:geo-update', 'right' => 'env.server.security', 'reversible' => self::REVERSIBLE],
        'env.security.web_protect' => ['command' => 'security:web-protect', 'right' => 'env.server.security', 'reversible' => self::REVERSIBLE],
        // The security list's own fixes of a site: what the security screen (env.server.operate's routes) starts.
        'env.security.certificate_renew' => ['command' => 'ssl:renew', 'right' => 'env.server.security', 'reversible' => self::REVERSIBLE],
        'env.security.backup_schedule' => ['command' => 'backup:data-schedule', 'right' => 'env.server.security', 'reversible' => self::REVERSIBLE],
        'env.firewall.rule_add' => ['command' => 'firewall:rule-add', 'right' => 'env.server.security', 'reversible' => self::TIMED],
        'env.firewall.rule_remove' => ['command' => 'firewall:rule-remove', 'right' => 'env.server.security', 'reversible' => self::TIMED],
        'env.firewall.block' => ['command' => 'firewall:block', 'right' => 'env.server.security', 'reversible' => self::TIMED],
        'env.firewall.unblock' => ['command' => 'firewall:unblock', 'right' => 'env.server.security', 'reversible' => self::TIMED],
        'env.mcp.enable' => ['command' => 'mcp:enable', 'right' => 'env.server.security', 'reversible' => self::REVERSIBLE],
        'env.mcp.disable' => ['command' => 'mcp:disable', 'right' => 'env.server.security', 'reversible' => self::REVERSIBLE],
        'env.mcp.key_add' => ['command' => 'mcp:key-add', 'right' => 'env.server.security', 'reversible' => self::REVERSIBLE],
        'env.mcp.key_remove' => ['command' => 'mcp:key-remove', 'right' => 'env.server.security', 'reversible' => self::REVERSIBLE],

        // Who reaches the server and the panel (env.server.access): accounts, the panel's address, keeping a timed change.
        'env.owner.create' => ['command' => 'owner:create', 'right' => 'env.server.access', 'reversible' => self::REVERSIBLE],
        'env.owner.block' => ['command' => 'owner:block', 'right' => 'env.server.access', 'reversible' => self::REVERSIBLE],
        'env.owner.unblock' => ['command' => 'owner:unblock', 'right' => 'env.server.access', 'reversible' => self::REVERSIBLE],
        'env.owner.remove' => ['command' => 'owner:remove', 'right' => 'env.server.access', 'reversible' => self::IRREVERSIBLE],
        'env.owner.add_key' => ['command' => 'owner:add-key', 'right' => 'env.server.access', 'reversible' => self::REVERSIBLE],
        'env.panel_access.allow' => ['command' => 'panel:access', 'right' => 'env.server.access', 'reversible' => self::TIMED],
        'env.panel_access.port' => ['command' => 'panel:port', 'right' => 'env.server.access', 'reversible' => self::TIMED],
        'env.panel_access.ip_access' => ['command' => 'panel:ip-access', 'right' => 'env.server.access', 'reversible' => self::TIMED],
        'env.panel_access.domain' => ['command' => 'panel:domain', 'right' => 'env.server.access', 'reversible' => self::TIMED],
        'env.change.confirm' => ['command' => 'change:confirm', 'right' => 'env.server.access', 'reversible' => self::REVERSIBLE],

        // Storages for the backups away from the server (env.server.backup_storage), and the way back from their copies.
        'env.backup_storage.add' => ['command' => 'backup:storage-add', 'right' => 'env.server.backup_storage', 'reversible' => self::REVERSIBLE],
        'env.backup_storage.test' => ['command' => 'backup:storage-test', 'right' => 'env.server.backup_storage', 'reversible' => self::REVERSIBLE],
        'env.backup_storage.remove' => ['command' => 'backup:storage-remove', 'right' => 'env.server.backup_storage', 'reversible' => self::REVERSIBLE],
        'env.backup_storage.fetch' => ['command' => 'backup:storage-fetch', 'right' => 'env.server.backup_storage', 'reversible' => self::REVERSIBLE],
        'env.backup_storage.return_site' => ['command' => 'site:add', 'right' => 'env.server.backup_storage', 'reversible' => self::REVERSIBLE],
        'env.backup_storage.return_database' => ['command' => 'site:db-create', 'right' => 'env.server.backup_storage', 'reversible' => self::REVERSIBLE],
        'env.backup_storage.restore' => ['command' => 'backup:data-restore', 'right' => 'env.server.backup_storage', 'reversible' => self::TIMED],

        // simai-env's or the panel's own update (env.server.update): no way back from the panel (root can pin an older tag).
        'env.server.update' => ['command' => 'self:update', 'right' => 'env.server.update', 'reversible' => self::IRREVERSIBLE],

        // DNS (env.dns.operate): the server's certificates through DNS, and the zones through the provider's API.
        'env.dns.certificates_credentials' => ['command' => 'ssl:dns-credentials', 'right' => 'env.dns.operate', 'reversible' => self::REVERSIBLE],
        'env.dns.connect' => ['command' => null, 'right' => 'env.dns.operate', 'reversible' => self::REVERSIBLE],
        'env.dns.disconnect' => ['command' => null, 'right' => 'env.dns.operate', 'reversible' => self::REVERSIBLE],
        'env.dns.records_apply' => ['command' => null, 'right' => 'env.dns.operate', 'reversible' => self::REVERSIBLE],
        // A site's own domain pointed at this server, at either level (routes/dns.php).
        'env.site.dns_point' => ['command' => null, 'right' => 'env.sites.operate', 'reversible' => self::REVERSIBLE, 'feature' => 'env.dns'],
    ];

    /** The audit events: one for every gateway change, the stop of a job, DNS's own. */
    public const string CHANGE_STARTED = 'env.server.change_started';
    public const string JOB_CANCELLED = 'env.server.job_cancelled';
    public const string DNS_CONNECTED = 'env.dns.connected';
    public const string DNS_DISCONNECTED = 'env.dns.disconnected';
    public const string DNS_RECORD_CHANGED = 'env.dns.record_changed';

    /**
     * The 16 rights by group (auditor's answer 1, variant B) and the right each new one is split from: whoever held that
     * right is given the new one once, when it first appears (access's lifecycle sync).
     *
     * @var array<string, string>
     */
    public const array SPLIT_FROM = [
        'env.sites.files' => 'env.sites.operate',
        'env.sites.database' => 'env.sites.operate',
        'env.sites.backup' => 'env.sites.operate',
        'env.sites.deploy' => 'env.sites.operate',
        'env.sites.remove' => 'env.sites.operate',
        'env.server.software' => 'env.server.operate',
        'env.server.security' => 'env.server.operate',
        'env.server.access' => 'env.server.operate',
        'env.server.backup_storage' => 'env.server.operate',
        'env.server.update' => 'env.server.operate',
    ];

    private function __construct()
    {
    }

    /** @return array{command: ?string, right: string, reversible: string, feature?: string}|null */
    public static function get(string $operation): ?array
    {
        return self::OPERATIONS[$operation] ?? null;
    }

    public static function has(string $operation): bool
    {
        return isset(self::OPERATIONS[$operation]);
    }

    /** The gateway command the operation is carried out by; null for DNS through the provider's API. */
    public static function command(string $operation): ?string
    {
        return self::OPERATIONS[$operation]['command'] ?? null;
    }

    public static function right(string $operation): ?string
    {
        return self::OPERATIONS[$operation]['right'] ?? null;
    }

    /** `true`, `false` or `timed`, as the audit payload carries it. */
    public static function reversibility(string $operation): ?string
    {
        return self::OPERATIONS[$operation]['reversible'] ?? null;
    }

    /** Core's boolean: a timed change undoes itself, so it counts as reversible. */
    public static function reversible(string $operation): bool
    {
        return (self::OPERATIONS[$operation]['reversible'] ?? self::IRREVERSIBLE) !== self::IRREVERSIBLE;
    }

    public static function handlerRef(string $operation): string
    {
        return self::command($operation) === null ? self::DNS_HANDLER : self::GATEWAY_HANDLER;
    }

    public static function auditEvent(string $operation): string
    {
        return match ($operation) {
            'env.job.cancel' => self::JOB_CANCELLED,
            'env.dns.connect' => self::DNS_CONNECTED,
            'env.dns.disconnect' => self::DNS_DISCONNECTED,
            'env.dns.records_apply', 'env.site.dns_point' => self::DNS_RECORD_CHANGED,
            default => self::CHANGE_STARTED,
        };
    }

    /** The feature that brings the operation: that of its right, unless the row names one. */
    public static function feature(string $operation): string
    {
        $row = self::OPERATIONS[$operation] ?? null;

        return $row['feature'] ?? EnvDeclarations::featureOf($row['right'] ?? '');
    }

    /** The operation the panel starts a command as where no screen names one (a job asked again): its first row. */
    public static function primaryFor(string $command): ?string
    {
        foreach (self::OPERATIONS as $operation => $row) {
            if ($row['command'] === $command) {
                return $operation;
            }
        }

        return null;
    }

    /** @return list<string> every operation of the command */
    public static function forCommand(string $command): array
    {
        return array_keys(array_filter(self::OPERATIONS, static fn (array $row): bool => $row['command'] === $command));
    }

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::OPERATIONS);
    }

    /**
     * The commands the operations carry out, against what PanelCommands allows: every action and `job:cancel`.
     *
     * @return list<string>
     */
    public static function mutatingCommands(): array
    {
        return [...PanelCommands::actions(), 'job:cancel'];
    }
}
