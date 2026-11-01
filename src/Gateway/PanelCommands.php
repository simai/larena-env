<?php

declare(strict_types=1);

namespace Larena\Env\Gateway;

/**
 * The commands this package may ask for, with the gateway's argument rules and its kind. A read answers data at once;
 * an action answers what it would do (a plan) and runs only when it is confirmed, as a job; a job command follows a
 * run that was started. The gateway checks everything again on its side: this table only keeps the panel from sending
 * what would be refused anyway.
 */
final class PanelCommands
{
    public const string READ = 'read';
    public const string ACTION = 'action';
    public const string JOB = 'job';

    private const string DOMAIN = '/\A(?=.{1,253}\z)[A-Za-z0-9](?:[A-Za-z0-9-]{0,62}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,62}[A-Za-z0-9])?)+\z/D';
    private const string YES_NO = '/\A(?:yes|no)\z/D';
    private const string EMAIL = '/\A[A-Za-z0-9._%+-]{1,64}@[A-Za-z0-9.-]{1,253}\.[A-Za-z]{2,63}\z/D';
    private const string LINES = '/\A(?:[1-9][0-9]{0,2}|1000)\z/D';
    private const string LOG_KIND = '/\A(?:access|error)\z/D';
    private const string JOB_ID = '/\A[a-f0-9]{24}\z/D';
    private const string CURSOR = '/\A[0-9]{1,7}\z/D';
    private const string OWNER = '/\A[a-z][a-z0-9_-]{0,31}\z/D';
    private const string PHP = '/\A[5-9]\.[0-9]\z/D';
    // bitrix and proxy only where the server lists its profiles (self:profiles, Panel plan 5.5).
    private const string PROFILE = '/\A(?:static|generic|laravel|wordpress|node|redirect|bitrix|proxy)\z/D';
    private const string ENGINE = '/\A(?:mysql|pgsql)\z/D';
    private const string ORIGIN = '/\Ahttps?:\/\/[A-Za-z0-9.-]{1,253}(?::[0-9]{1,5})?\z/D';
    private const string CODE = '/\A30[1278]\z/D';
    private const string KEEP_DROP = '/\A(?:keep|drop)\z/D';
    private const string PUBKEY = '/\A(?:ssh-ed25519|ssh-rsa|ecdsa-sha2-nistp(?:256|384|521)|sk-ssh-ed25519@openssh\.com) [A-Za-z0-9+\/]{20,8192}={0,3}(?: [ -~]{0,200})?\z/D';
    private const string BACKUP = '/\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/D';
    private const string TIME = '/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]\z/D';
    private const string KEEP = '/\A(?:[1-9]|[1-8][0-9]|90)\z/D';
    private const string INI_KEY = '/\A[a-z][a-z0-9_.]{0,63}\z/D';
    private const string INI_VALUE = '/\A[A-Za-z0-9 ._:\/+-]{0,128}\z/D';
    private const string LOGIN = '/\A[a-z][a-z0-9_-]{0,31}\z/D';
    // A fixed string the log's lines are searched for: printable ASCII, up to 200 characters (simai-env 1.28.0).
    private const string MATCH = '/\A[ -~]{1,200}\z/D';
    // A path inside a site, from its root: never absolute, never a `..` segment, no control characters (Panel plan 6.1).
    // `.` is the root itself. The gateway checks every component again on the host (path_outside).
    private const string FS_PATH = '/\A(?!\/)(?!(?:.*\/)?\.\.(?:\/|\z))[^\x00-\x1f\x7f]{1,1024}\z/D';
    private const string FS_NAME = '/\A(?!\.{1,2}\z)[^\/\x00-\x1f\x7f]{1,255}\z/D';
    private const string FS_MODE = '/\A0?[0-7]{3}\z/D';
    private const string FS_MAX_BYTES = '/\A[1-9][0-9]{0,6}\z/D';
    private const string FS_TRASH_ID = '/\A[A-Za-z0-9][A-Za-z0-9._-]{0,127}\z/D';
    private const string FS_FORMAT = '/\A(?:zip|tar\.gz)\z/D';
    private const string CHANGE_ID = '/\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/D';
    // The server's alerts and where it sends them (simai-env 1.31.0). A webhook is https only; a Telegram chat is its
    // id or @name; the bot's token travels as text and reaches the command as a file, shown as (stored) everywhere.
    private const string ALERT_ID = '/\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/D';
    private const string ALERT_CHANNEL = '/\A(?:webhook|telegram|off)\z/D';
    private const string ALERT_TARGET = '/\A(?:https:\/\/[!-~]{1,500}|-?[0-9]{1,20}|@[A-Za-z0-9_]{5,32})\z/D';
    private const string BOT_TOKEN = '/\A[0-9]{5,12}:[A-Za-z0-9_-]{30,64}\z/D';
    private const string PERIOD = '/\A(?:day|week|month)\z/D';
    // What a safe update replaces: simai-env itself, or the panel's build (simai-env 1.32.0).
    private const string UPDATE_TARGET = '/\A(?:env|panel)\z/D';
    // A service of the server's fixed list, only those it has (simai-env 1.33.0).
    private const string SERVICE = '/\A(?:nginx|php[5-9]\.[0-9]-fpm|mysql|mariadb|postgresql|redis-server|cron)\z/D';
    // A proxy site forwards to a port of this same server only (127.0.0.1), never nginx's own 80 or 443, and a path on it.
    private const string PORT = '/\A(?!(?:80|443)\z)(?:[1-9][0-9]{0,3}|[1-5][0-9]{4}|6[0-4][0-9]{3}|65[0-4][0-9]{2}|655[0-2][0-9]|6553[0-5])\z/D';
    // A 1C-Bitrix site's installer in its root: none yet, bitrixsetup.php, or restore.php.
    private const string BITRIX_FILES = '/\A(?:none|setup|restore)\z/D';
    private const string UPSTREAM_PATH = '/\A\/[A-Za-z0-9._~\/-]{0,200}\z/D';
    // A site's own scheduled job: five fields, no more often than every minute (the server checks it again), and one
    // line of printable text run by the site's user.
    private const string CRON_SCHEDULE = '/\A[0-9A-Za-z*\/,-]{1,40}(?: [0-9A-Za-z*\/,-]{1,40}){4}\z/D';
    private const string CRON_COMMAND = '/\A[^\x00-\x1f\x7f]{1,1000}\z/Du';
    private const string CRON_ID = '/\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/D';
    // The firewall (ufw): a port or a range, a protocol, where from, and what to do; an address or a network to block.
    private const string FW_PORT = '/\A[1-9][0-9]{0,4}(?::[1-9][0-9]{0,4})?\z/D';
    private const string FW_PROTO = '/\A(?:tcp|udp|any)\z/D';
    private const string FW_ACTION = '/\A(?:allow|deny)\z/D';
    private const string ADDRESS = '/\A(?:[0-9]{1,3}(?:\.[0-9]{1,3}){3}(?:\/(?:[0-9]|[12][0-9]|3[0-2]))?|[0-9A-Fa-f]{0,4}(?::[0-9A-Fa-f]{0,4}){2,7}(?:\/[0-9]{1,3})?)\z/D';
    private const string FW_FROM = '/\A(?:any|[0-9]{1,3}(?:\.[0-9]{1,3}){3}(?:\/(?:[0-9]|[12][0-9]|3[0-2]))?|[0-9A-Fa-f]{0,4}(?::[0-9A-Fa-f]{0,4}){2,7}(?:\/[0-9]{1,3})?)\z/D';
    private const string FW_RULE_ID = '/\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/D';
    private const string TTL = '/\A[1-9][0-9]{0,7}\z/D';
    // Who reaches the panel: `any`, or a list of addresses and networks (panel:access, the list's one argument).
    private const string PANEL_ALLOW = '/\A(?:any|[0-9]{1,3}(?:\.[0-9]{1,3}){3}(?:\/(?:[0-9]|[12][0-9]|3[0-2]))?|[0-9A-Fa-f]{0,4}(?::[0-9A-Fa-f]{0,4}){2,7}(?:\/[0-9]{1,3})?)\z/D';
    /**
     * The arguments the gateway takes as a list: fs:archive's paths, up to 100, each checked as a path; the addresses
     * that reach the panel (panel:access), up to 50.
     */
    private const array LIST_ARGUMENTS = ['fs:archive' => ['paths' => 100], 'panel:access' => ['allow' => 50]];

    /**
     * `since`: the first simai-env release whose gateway allows the command; null for one no release has yet.
     *
     * @var array<string, array{args: array<string, string>, required: list<string>, since: ?string, kind: string}>
     */
    private const array COMMANDS = [
        'self:describe' => ['args' => [], 'required' => [], 'since' => '1.20.0', 'kind' => self::READ],
        'self:commands' => ['args' => [], 'required' => [], 'since' => '1.20.0', 'kind' => self::READ],
        'site:describe' => ['args' => ['domain' => self::DOMAIN], 'required' => ['domain'], 'since' => '1.20.0', 'kind' => self::READ],
        'site:doctor' => ['args' => ['domain' => self::DOMAIN, 'strict' => self::YES_NO, 'include-target' => self::YES_NO],
            'required' => ['domain'], 'since' => '1.20.0', 'kind' => self::READ],
        'logs:nginx' => ['args' => ['domain' => self::DOMAIN, 'kind' => self::LOG_KIND, 'lines' => self::LINES, 'match' => self::MATCH],
            'required' => ['domain'], 'since' => '1.21.0', 'kind' => self::READ],
        // Actions of the certificates, backups, releases and services screens (simai-env 1.21.0).
        'ssl:letsencrypt' => ['args' => ['domain' => self::DOMAIN, 'email' => self::EMAIL, 'redirect' => self::YES_NO,
            'hsts' => self::YES_NO, 'staging' => self::YES_NO], 'required' => ['domain', 'email'], 'since' => '1.21.0', 'kind' => self::ACTION],
        'ssl:renew' => ['args' => ['domain' => self::DOMAIN], 'required' => ['domain'], 'since' => '1.21.0', 'kind' => self::ACTION],
        'backup:data' => ['args' => ['domain' => self::DOMAIN, 'db' => self::YES_NO, 'files' => self::YES_NO],
            'required' => ['domain'], 'since' => '1.21.0', 'kind' => self::ACTION],
        'site:deploy-rollback' => ['args' => ['domain' => self::DOMAIN], 'required' => ['domain'], 'since' => '1.21.0', 'kind' => self::ACTION],
        'site:service-restart' => ['args' => ['domain' => self::DOMAIN], 'required' => ['domain'], 'since' => '1.21.0', 'kind' => self::ACTION],
        'queue:restart' => ['args' => ['domain' => self::DOMAIN], 'required' => ['domain'], 'since' => '1.21.0', 'kind' => self::ACTION],
        // A new site and its PHP; a new owner, the space of a person at the panel's user level (simai-env 1.22.0).
        'site:add' => ['args' => ['domain' => self::DOMAIN, 'profile' => self::PROFILE, 'php' => self::PHP, 'owner' => self::OWNER,
            'create-db' => self::YES_NO, 'db-engine' => self::ENGINE, 'redirect-to' => self::ORIGIN, 'redirect-code' => self::CODE,
            'redirect-path' => self::KEEP_DROP, 'backup' => self::YES_NO, 'upstream-port' => self::PORT, 'upstream-path' => self::UPSTREAM_PATH,
            'websockets' => self::YES_NO, 'bitrix-files' => self::BITRIX_FILES], 'required' => ['domain'], 'since' => '1.22.0', 'kind' => self::ACTION],
        'site:set-php' => ['args' => ['domain' => self::DOMAIN, 'php' => self::PHP], 'required' => ['domain', 'php'], 'since' => '1.22.0', 'kind' => self::ACTION],
        'owner:create' => ['args' => ['name' => self::OWNER, 'pubkey' => self::PUBKEY], 'required' => ['name'], 'since' => '1.22.0', 'kind' => self::ACTION],
        // Blocking a space: SSH, SFTP and requests as that owner stop, its sites keep serving (simai-env 1.27.0).
        'owner:block' => ['args' => ['name' => self::OWNER], 'required' => ['name'], 'since' => '1.27.0', 'kind' => self::ACTION],
        'owner:unblock' => ['args' => ['name' => self::OWNER], 'required' => ['name'], 'since' => '1.27.0', 'kind' => self::ACTION],
        'site:db-create' => ['args' => ['domain' => self::DOMAIN], 'required' => ['domain'], 'since' => '1.22.0', 'kind' => self::ACTION],
        // Panel plan stage 3 (simai-env 1.28.0): restore, check and schedule backups, PHP's extensions and php.ini, the
        // server's load (administrator only), the databases and a new database password.
        'backup:data-restore' => ['args' => ['domain' => self::DOMAIN, 'backup' => self::BACKUP, 'db' => self::YES_NO, 'files' => self::YES_NO],
            'required' => ['domain', 'backup'], 'since' => '1.28.0', 'kind' => self::ACTION],
        'backup:data-schedule' => ['args' => ['domain' => self::DOMAIN, 'enabled' => self::YES_NO, 'time' => self::TIME, 'keep' => self::KEEP,
            'verify' => self::YES_NO],
            'required' => ['domain', 'enabled'], 'since' => '1.28.0', 'kind' => self::ACTION],
        'backup:data-verify' => ['args' => ['domain' => self::DOMAIN, 'backup' => self::BACKUP], 'required' => ['domain', 'backup'], 'since' => '1.28.0', 'kind' => self::ACTION],
        'php:extensions' => ['args' => ['php' => self::PHP], 'required' => ['php'], 'since' => '1.28.0', 'kind' => self::READ],
        'site:php-ini-list' => ['args' => ['domain' => self::DOMAIN], 'required' => ['domain'], 'since' => '1.28.0', 'kind' => self::READ],
        'site:php-ini-set' => ['args' => ['domain' => self::DOMAIN, 'name' => self::INI_KEY, 'value' => self::INI_VALUE],
            'required' => ['domain', 'name', 'value'], 'since' => '1.28.0', 'kind' => self::ACTION],
        'site:php-ini-unset' => ['args' => ['domain' => self::DOMAIN, 'name' => self::INI_KEY], 'required' => ['domain', 'name'], 'since' => '1.28.0', 'kind' => self::ACTION],
        'self:metrics' => ['args' => [], 'required' => [], 'since' => '1.28.0', 'kind' => self::READ],
        // The server's own watch, also while the panel is closed or down, its channel and its load history (1.31.0).
        'self:alerts' => ['args' => [], 'required' => [], 'since' => '1.31.0', 'kind' => self::READ],
        'self:alerts-ack' => ['args' => ['id' => self::ALERT_ID], 'required' => ['id'], 'since' => '1.31.0', 'kind' => self::ACTION],
        'self:alerts-channel' => ['args' => ['kind' => self::ALERT_CHANNEL, 'target' => self::ALERT_TARGET, 'token' => self::BOT_TOKEN],
            'required' => ['kind'], 'since' => '1.31.0', 'kind' => self::ACTION],
        'self:alerts-test' => ['args' => [], 'required' => [], 'since' => '1.31.0', 'kind' => self::ACTION],
        'self:metrics-history' => ['args' => ['period' => self::PERIOD], 'required' => ['period'], 'since' => '1.31.0', 'kind' => self::READ],
        // A safe update of simai-env or of the panel, administrator only (simai-env 1.32.0): what it changes, then the job
        // that takes a copy, replaces, checks the sites or the panel's health, and puts the previous one back if they fail.
        'self:update-plan' => ['args' => ['target' => self::UPDATE_TARGET], 'required' => ['target'], 'since' => '1.32.0', 'kind' => self::READ],
        'self:update' => ['args' => ['target' => self::UPDATE_TARGET], 'required' => ['target'], 'since' => '1.32.0', 'kind' => self::ACTION],
        // SFTP accounts of a site, by key only: there is no password argument (simai-env 1.30.0).
        'access:list' => ['args' => ['domain' => self::DOMAIN], 'required' => [], 'since' => '1.30.0', 'kind' => self::READ],
        'access:create-project' => ['args' => ['domain' => self::DOMAIN, 'login' => self::LOGIN, 'pubkey' => self::PUBKEY],
            'required' => ['domain', 'login', 'pubkey'], 'since' => '1.30.0', 'kind' => self::ACTION],
        'access:add-key' => ['args' => ['login' => self::LOGIN, 'pubkey' => self::PUBKEY], 'required' => ['login', 'pubkey'], 'since' => '1.30.0', 'kind' => self::ACTION],
        'access:disable' => ['args' => ['login' => self::LOGIN], 'required' => ['login'], 'since' => '1.30.0', 'kind' => self::ACTION],
        'access:enable' => ['args' => ['login' => self::LOGIN], 'required' => ['login'], 'since' => '1.30.0', 'kind' => self::ACTION],
        'access:remove' => ['args' => ['login' => self::LOGIN], 'required' => ['login'], 'since' => '1.30.0', 'kind' => self::ACTION],
        'db:list' => ['args' => [], 'required' => [], 'since' => '1.28.0', 'kind' => self::READ],
        'site:db-rotate' => ['args' => ['domain' => self::DOMAIN], 'required' => ['domain'], 'since' => '1.28.0', 'kind' => self::ACTION],
        // Panel plan stage 5. The server's services, the administrator's, each checked before a restart or a reload (nginx
        // -t, php-fpm -t), and the kinds of site the server makes, with their arguments (simai-env 1.33.0); a site's own
        // scheduled jobs, as its user (1.34.0); the firewall and who reaches the panel, each change undone by the server
        // itself unless it is kept in time with change:confirm (1.35.0): those wait for their release, unlisted.
        'service:list' => ['args' => [], 'required' => [], 'since' => '1.33.0', 'kind' => self::READ],
        'service:restart' => ['args' => ['name' => self::SERVICE], 'required' => ['name'], 'since' => '1.33.0', 'kind' => self::ACTION],
        'service:reload' => ['args' => ['name' => self::SERVICE], 'required' => ['name'], 'since' => '1.33.0', 'kind' => self::ACTION],
        'service:log' => ['args' => ['name' => self::SERVICE, 'lines' => self::LINES], 'required' => ['name'], 'since' => '1.33.0', 'kind' => self::READ],
        'self:profiles' => ['args' => [], 'required' => [], 'since' => '1.33.0', 'kind' => self::READ],
        'cron:list' => ['args' => ['domain' => self::DOMAIN], 'required' => ['domain'], 'since' => null, 'kind' => self::READ],
        'cron:job-add' => ['args' => ['domain' => self::DOMAIN, 'schedule' => self::CRON_SCHEDULE, 'command' => self::CRON_COMMAND],
            'required' => ['domain', 'schedule', 'command'], 'since' => null, 'kind' => self::ACTION],
        'cron:job-remove' => ['args' => ['id' => self::CRON_ID], 'required' => ['id'], 'since' => null, 'kind' => self::ACTION],
        'cron:job-enable' => ['args' => ['id' => self::CRON_ID], 'required' => ['id'], 'since' => null, 'kind' => self::ACTION],
        'cron:job-disable' => ['args' => ['id' => self::CRON_ID], 'required' => ['id'], 'since' => null, 'kind' => self::ACTION],
        'cron:job-runs' => ['args' => ['id' => self::CRON_ID], 'required' => ['id'], 'since' => null, 'kind' => self::READ],
        'firewall:status' => ['args' => [], 'required' => [], 'since' => null, 'kind' => self::READ],
        'firewall:rule-add' => ['args' => ['port' => self::FW_PORT, 'proto' => self::FW_PROTO, 'from' => self::FW_FROM, 'action' => self::FW_ACTION],
            'required' => ['port', 'action'], 'since' => null, 'kind' => self::ACTION],
        'firewall:rule-remove' => ['args' => ['id' => self::FW_RULE_ID], 'required' => ['id'], 'since' => null, 'kind' => self::ACTION],
        'firewall:block' => ['args' => ['address' => self::ADDRESS, 'ttl' => self::TTL], 'required' => ['address'], 'since' => null, 'kind' => self::ACTION],
        'firewall:unblock' => ['args' => ['address' => self::ADDRESS], 'required' => ['address'], 'since' => null, 'kind' => self::ACTION],
        'panel:access' => ['args' => ['allow' => self::PANEL_ALLOW], 'required' => ['allow'], 'since' => null, 'kind' => self::ACTION],
        // Files of a site, as its own user and inside its root only (simai-env, after 1.35.0; Panel plan 6.1, 6.2).
        'fs:list' => ['args' => ['domain' => self::DOMAIN, 'path' => self::FS_PATH], 'required' => ['domain', 'path'], 'since' => null, 'kind' => self::READ],
        'fs:read' => ['args' => ['domain' => self::DOMAIN, 'path' => self::FS_PATH, 'max_bytes' => self::FS_MAX_BYTES], 'required' => ['domain', 'path'], 'since' => null, 'kind' => self::READ],
        'fs:trash-list' => ['args' => ['domain' => self::DOMAIN], 'required' => ['domain'], 'since' => null, 'kind' => self::READ],
        'fs:download' => ['args' => ['domain' => self::DOMAIN, 'path' => self::FS_PATH], 'required' => ['domain', 'path'], 'since' => null, 'kind' => self::READ],
        'fs:write' => ['args' => ['domain' => self::DOMAIN, 'path' => self::FS_PATH, 'mode' => self::FS_MODE], 'required' => ['domain', 'path'], 'since' => null, 'kind' => self::ACTION],
        'fs:upload' => ['args' => ['domain' => self::DOMAIN, 'path' => self::FS_PATH, 'overwrite' => self::YES_NO], 'required' => ['domain', 'path'], 'since' => null, 'kind' => self::ACTION],
        'fs:mkdir' => ['args' => ['domain' => self::DOMAIN, 'path' => self::FS_PATH], 'required' => ['domain', 'path'], 'since' => null, 'kind' => self::ACTION],
        'fs:rename' => ['args' => ['domain' => self::DOMAIN, 'from' => self::FS_PATH, 'to' => self::FS_PATH], 'required' => ['domain', 'from', 'to'], 'since' => null, 'kind' => self::ACTION],
        'fs:copy' => ['args' => ['domain' => self::DOMAIN, 'from' => self::FS_PATH, 'to' => self::FS_PATH], 'required' => ['domain', 'from', 'to'], 'since' => null, 'kind' => self::ACTION],
        'fs:delete' => ['args' => ['domain' => self::DOMAIN, 'path' => self::FS_PATH, 'recursive' => self::YES_NO], 'required' => ['domain', 'path'], 'since' => null, 'kind' => self::ACTION],
        'fs:restore' => ['args' => ['domain' => self::DOMAIN, 'id' => self::FS_TRASH_ID], 'required' => ['domain', 'id'], 'since' => null, 'kind' => self::ACTION],
        'fs:chmod' => ['args' => ['domain' => self::DOMAIN, 'path' => self::FS_PATH, 'mode' => self::FS_MODE, 'recursive' => self::YES_NO], 'required' => ['domain', 'path', 'mode'], 'since' => null, 'kind' => self::ACTION],
        'fs:archive' => ['args' => ['domain' => self::DOMAIN, 'paths' => self::FS_PATH, 'name' => self::FS_NAME, 'format' => self::FS_FORMAT], 'required' => ['domain', 'paths', 'name', 'format'], 'since' => null, 'kind' => self::ACTION],
        'fs:extract' => ['args' => ['domain' => self::DOMAIN, 'path' => self::FS_PATH, 'into' => self::FS_PATH], 'required' => ['domain', 'path', 'into'], 'since' => null, 'kind' => self::ACTION],
        // The site's own nginx directives, the system level's (Panel plan 6.3): applied, then kept only once confirmed.
        'site:nginx-overlay-get' => ['args' => ['domain' => self::DOMAIN], 'required' => ['domain'], 'since' => null, 'kind' => self::READ],
        'site:nginx-overlay-set' => ['args' => ['domain' => self::DOMAIN], 'required' => ['domain'], 'since' => null, 'kind' => self::ACTION],
        'site:nginx-overlay-clear' => ['args' => ['domain' => self::DOMAIN], 'required' => ['domain'], 'since' => null, 'kind' => self::ACTION],
        // A timed change kept by the person who made it (the firewall, the panel's access, nginx directives): without the
        // confirmation in time, the server puts the previous one back by itself.
        'change:confirm' => ['args' => ['change_id' => self::CHANGE_ID], 'required' => ['change_id'], 'since' => null, 'kind' => self::ACTION],
        'change:status' => ['args' => ['change_id' => self::CHANGE_ID], 'required' => ['change_id'], 'since' => null, 'kind' => self::READ],
        'job:status' => ['args' => ['job_id' => self::JOB_ID], 'required' => ['job_id'], 'since' => '1.21.0', 'kind' => self::JOB],
        'job:log' => ['args' => ['job_id' => self::JOB_ID, 'after' => self::CURSOR], 'required' => ['job_id'], 'since' => '1.21.0', 'kind' => self::JOB],
        'job:cancel' => ['args' => ['job_id' => self::JOB_ID], 'required' => ['job_id'], 'since' => '1.21.0', 'kind' => self::JOB],
        'job:list' => ['args' => [], 'required' => [], 'since' => '1.21.0', 'kind' => self::JOB],
    ];

    /**
     * Arguments a later simai-env release added to a command the panel already sends; an older gateway refuses them.
     *
     * @var array<string, array<string, string>>
     */
    private const array ARGUMENTS_SINCE = [
        // A new site's daily backup and the check of each scheduled copy (simai-env 1.30.0).
        // An app on a local port and a 1C-Bitrix site's installer (simai-env 1.33.0).
        'site:add' => ['backup' => '1.30.0', 'upstream-port' => '1.33.0', 'upstream-path' => '1.33.0', 'websockets' => '1.33.0', 'bitrix-files' => '1.33.0'],
        'backup:data-schedule' => ['verify' => '1.30.0'],
    ];

    /**
     * Commands whose content travels as an attachment after the request line ({sha256, size}, then exactly size bytes),
     * as site:deploy's release does; a plan carries only the metadata. With the most each one takes.
     */
    private const array ATTACHMENTS = [
        'fs:write' => 5_242_880,
        'fs:upload' => 536_870_912,
        'site:nginx-overlay-set' => 65_536,
    ];

    /** A download answers a JSON line and then exactly `size` bytes: the most the panel takes. */
    public const int DOWNLOAD_MAX_BYTES = 2_147_483_648;

    private function __construct()
    {
    }

    /** The most bytes the command's attachment may have; null for a command that takes none. */
    public static function attachmentLimit(string $command): ?int
    {
        return self::ATTACHMENTS[$command] ?? null;
    }

    /** Whether the command's answer is followed by the file's bytes (fs:download): it is never asked the usual way. */
    public static function streams(string $command): bool
    {
        return $command === 'fs:download';
    }

    /** The first simai-env release that takes the argument; that of the command for one it always took. */
    public static function argumentSince(string $command, string $argument): ?string
    {
        return self::ARGUMENTS_SINCE[$command][$argument] ?? self::since($command);
    }

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::COMMANDS);
    }

    /** `read`, `action` or `job`; null for a command this package may not send. */
    public static function kind(string $command): ?string
    {
        return self::COMMANDS[$command]['kind'] ?? null;
    }

    /** The first simai-env release whose gateway allows the command; null when none does yet or the command is unknown. */
    public static function since(string $command): ?string
    {
        return self::COMMANDS[$command]['since'] ?? null;
    }

    /** @return list<string> the action commands, the only ones that are planned and confirmed */
    public static function actions(): array
    {
        return array_keys(array_filter(self::COMMANDS, static fn (array $rules): bool => $rules['kind'] === self::ACTION));
    }

    /**
     * @param array<array-key, mixed> $args a list only where LIST_ARGUMENTS allows one
     *
     * @throws PanelRefused
     */
    public static function check(string $command, array $args, bool $confirm = false): void
    {
        $rules = self::COMMANDS[$command] ?? null;
        if ($rules === null) {
            throw new PanelRefused(PanelReason::NOT_ALLOWED);
        }
        // Only an action is ever confirmed; the gateway refuses a confirmed read or job the same way.
        if ($confirm && $rules['kind'] !== self::ACTION) {
            throw new PanelRefused(PanelReason::NOT_ALLOWED);
        }
        foreach ($args as $name => $value) {
            $pattern = $rules['args'][$name] ?? null;
            $most = self::LIST_ARGUMENTS[$command][$name] ?? null;
            $values = $most !== null && is_array($value) && array_is_list($value) && $value !== [] && count($value) <= $most ? $value : [$value];
            foreach ($values as $item) {
                if ($pattern === null || !is_string($item) || preg_match($pattern, $item) !== 1) {
                    throw new PanelRefused(PanelReason::INVALID_ARGUMENT);
                }
            }
        }
        foreach ($rules['required'] as $name) {
            if (!array_key_exists($name, $args)) {
                throw new PanelRefused(PanelReason::INVALID_ARGUMENT);
            }
        }
    }
}
