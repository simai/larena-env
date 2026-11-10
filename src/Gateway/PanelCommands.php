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
    // An archive file:archive makes: a path whose name says the format.
    private const string FS_ARCHIVE = '/\A(?!\/)(?!(?:.*\/)?\.\.(?:\/|\z))[^\x00-\x1f\x7f]{1,1024}\.(?:zip|tar\.gz|tgz|tar)\z/D';
    // A file's text or a site's nginx directives in the request line, which the gateway takes up to 64 KiB in all.
    private const string FILE_CONTENT = '/\A[^\x00]{0,61440}\z/D';
    // What file:write and file:upload expect at the path: the sha256 read before, nothing yet, or anything.
    private const string EXPECT_SHA256 = '/\A(?:[a-f0-9]{64}|new|any)\z/D';
    private const string CHANGE_ID = '/\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/D';
    // The server's alerts and where it sends them (simai-env 1.31.0). A webhook is https only; a Telegram chat is its
    // id or @name; the bot's token travels as text and reaches the command as a file, shown as (stored) everywhere.
    private const string ALERT_ID = '/\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/D';
    private const string ALERT_CHANNEL = '/\A(?:webhook|telegram|off)\z/D';
    private const string ALERT_TARGET = '/\A(?:https:\/\/[!-~]{1,500}|-?[0-9]{1,20}|@[A-Za-z0-9_]{5,32})\z/D';
    private const string BOT_TOKEN = '/\A[0-9]{5,12}:[A-Za-z0-9_-]{30,64}\z/D';
    // The history's periods: an hour and six hours since simai-env 1.50.0, an older server refuses them.
    // A key's fingerprint as ssh-keygen prints it (simai-env 1.49.0, MCP keys).
    private const string FINGERPRINT = '/\A(?:SHA256:[A-Za-z0-9+\/]{8,64})\z/D';
    private const string YES = '/\A(?:yes)\z/D';
    // A storage for backups away from the server (simai-env 1.44.0): its name, the S3 bucket, region, folder and keys.
    private const string STORAGE = '/\A[a-z0-9][a-z0-9-]{0,31}\z/D';
    private const string BUCKET = '/\A[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]\z/D';
    private const string REGION = '/\A[a-z0-9-]{1,32}\z/D';
    private const string PREFIX = '/\A(?!.*\.\.)[A-Za-z0-9._-][A-Za-z0-9._\/-]{0,127}\z/D';
    private const string S3_KEY = '/\A[A-Za-z0-9\/+=._-]{8,256}\z/D';
    private const string KEEP_DAYS = '/\A(?:[1-9]|[1-8][0-9]|90)\z/D';
    private const string STAMP = '/\A[0-9]{8}-[0-9]{6}\z/D';
    // A profile rule of a site's check by its stable id, as site:doctor names it in `fix` (simai-env 1.55.1): bitrix.db-charset.
    private const string CHECK_ID = '/\A[a-z0-9][a-z0-9._-]{0,63}\z/D';
    private const string PERIOD = '/\A(?:hour|6h|day|week|month)\z/D';
    // A site's creation route (simai-env 1.56.0). A Bitrix backup's base file and where it is: a folder of the site, from its
    // root, without `..` or a leading `/` (`source`), or an https address ending with the archive's name (`url`), never both.
    private const string BITRIX_ARCHIVE = '/\A[A-Za-z0-9][A-Za-z0-9._-]{0,200}\.(?:tar\.gz|tgz|tar|enc\.gz)\z/D';
    private const string SITE_FOLDER = '/\A(?!\/)(?!(?:.*\/)?\.{1,2}(?:\/|\z))[A-Za-z0-9._\/-]{1,255}\z/D';
    private const string ARCHIVE_URL = '/\Ahttps:\/\/[A-Za-z0-9.-]{1,253}(?::[0-9]{1,5})?\/[!-~]{0,1500}\.(?:tar\.gz|tgz|tar|enc\.gz)(?:\?[!-~]{0,500})?\z/D';
    // WordPress by one click: the site's title (not starting with `-`), its administrator's login and the language.
    private const string WP_TITLE = '/\A(?![-\s])[^\x00-\x1f\x7f]{1,200}\z/Du';
    private const string WP_USER = '/\A[A-Za-z0-9][A-Za-z0-9._@-]{0,59}\z/D';
    private const string WP_LOCALE = '/\A[a-z]{2,3}(?:_[A-Z]{2})?\z/D';
    // A release from Git (site:deploy, the Laravel repository route of simai-env 1.56.2): the repository by https or as
    // git@host:path, and the branch, tag or commit, as the gateway takes them (GIT_URL, GIT_REF).
    private const string GIT_URL = '/\A(?:https:\/\/[A-Za-z0-9.-]{1,253}(?::[0-9]{1,5})?\/[A-Za-z0-9._~\/-]{1,300}|git@[A-Za-z0-9.-]{1,253}:[A-Za-z0-9._~\/-]{1,300})\z/D';
    private const string GIT_REF = '/\A[A-Za-z0-9_][A-Za-z0-9._\/-]{0,199}\z/D';
    // The server's security (simai-env 1.45.0–1.47.0): what security:fix puts right, the protection levels, and the
    // requests one address may send a second (2–1000) and at once (10–10000).
    private const string SECURITY_FIX = '/\A(?:auto_updates|fail2ban|ssh_password|antivirus)\z/D';
    private const string SECURITY_LEVEL = '/\A(?:basic|enhanced|strict)\z/D';
    private const string WEB_RATE = '/\A(?:[2-9]|[1-9][0-9]{1,2}|1000)\z/D';
    private const string WEB_BURST = '/\A(?:[1-9][0-9]{1,3}|10000)\z/D';
    // Country blocking (simai-env 1.48.0): off, deny or allow only a list of up to 100 ISO 3166 codes, on the web ports
    // or all of them, and up to 20 addresses or networks never dropped; the server checks each address again.
    private const string GEO_MODE = '/\A(?:off|deny|allow)\z/D';
    private const string GEO_COUNTRIES = '/\A[A-Z]{2}(?:,[A-Z]{2}){0,99}\z/D';
    private const string GEO_PORTS = '/\A(?:web|all)\z/D';
    private const string GEO_KEEP = '/\A[0-9A-Fa-f:.\/]{2,49}(?:,[0-9A-Fa-f:.\/]{2,49}){0,19}\z/D';
    private const string IP = '/\A(?:[0-9]{1,3}(?:\.[0-9]{1,3}){3}|[0-9A-Fa-f]{0,4}(?::[0-9A-Fa-f]{0,4}){2,7})\z/D';
    // Certificates through Cloudflare DNS (simai-env 1.36.0): the API token is stored on the server once, sent as text
    // that reaches the command as a file and is shown as (stored) everywhere; the challenge http or dns.
    private const string DNS_PROVIDER = '/\A(?:cloudflare)\z/D';
    private const string CF_TOKEN = '/\A[A-Za-z0-9_-]{30,64}\z/D';
    private const string CHALLENGE = '/\A(?:http|dns)\z/D';
    // What a safe update replaces: simai-env itself, or the panel's build (simai-env 1.32.0).
    private const string UPDATE_TARGET = '/\A(?:env|panel)\z/D';
    // A service of the server's fixed list, only those it has (simai-env 1.33.0).
    // A program software:install adds or updates and software:remove takes away (simai-env 1.43.0's catalogue; 1.38.0
    // took node, redis and postgresql), and the major version node and postgresql may take.
    private const string SOFTWARE_NAME = '/\A(?:node|redis|postgresql|apache|certbot|fail2ban|memcached|imagemagick|jpegoptim|optipng|ffmpeg|supervisor)\z/D';
    private const string MAJOR = '/\A[0-9]{2}\z/D';
    // A PHP extension's name, as php:extension takes it (simai-env 1.37.0).
    private const string PHP_EXT = '/\A[a-z0-9_]{2,32}\z/D';
    private const string SERVICE = '/\A(?:nginx|php[5-9]\.[0-9]-fpm|mysql|mariadb|postgresql|redis-server|cron)\z/D';
    // A proxy site forwards to a port of this same server only (127.0.0.1), never nginx's own 80 or 443, and a path on it.
    private const string PORT = '/\A(?!(?:80|443)\z)(?:[1-9][0-9]{0,3}|[1-5][0-9]{4}|6[0-4][0-9]{3}|65[0-4][0-9]{2}|655[0-2][0-9]|6553[0-5])\z/D';
    // A 1C-Bitrix site's installer in its root: none yet, bitrixsetup.php, or restore.php.
    private const string BITRIX_FILES = '/\A(?:none|setup|restore)\z/D';
    // How a site's extra name answers (simai-env 1.53.0): it shows the same site, or sends visitors to the main domain.
    private const string NAME_MODE = '/\A(?:serve|redirect)\z/D';
    private const string UPSTREAM_PATH = '/\A\/[A-Za-z0-9._~\/-]{0,200}\z/D';
    // A site's own scheduled job (simai-env 1.34.0): five fields of numbers, `*`, ranges and steps (their ranges checked
    // by CronSchedule::valid, as the server does), and one line of printable text run by the site's user, not starting
    // with "-"; a job's id is the server's eight hex digits; up to 50 runs are read.
    private const string CRON_SCHEDULE = '/\A[0-9*\/,-]{1,40}(?: [0-9*\/,-]{1,40}){4}\z/D';
    private const string CRON_COMMAND = '/\A(?![-\s])[^\x00-\x1f\x7f]{1,1000}(?<!\s)\z/Du';
    private const string CRON_ID = '/\A[a-f0-9]{8}\z/D';
    private const string CRON_LIMIT = '/\A(?:[1-9]|[1-4][0-9]|50)\z/D';
    // The firewall (ufw, simai-env 1.35.0): one port, tcp or udp, from anywhere or an address or network, allowed or
    // denied; a rule's id is the server's eight hex digits; an address is blocked for 60 seconds to a year, or until
    // unblocked (0), the range checked by the server. The server checks every address fully again.
    private const string FW_PORT = '/\A[1-9][0-9]{0,4}\z/D';
    private const string FW_PROTO = '/\A(?:tcp|udp)\z/D';
    private const string FW_ACTION = '/\A(?:allow|deny)\z/D';
    private const string ADDRESS = '/\A(?:[0-9]{1,3}(?:\.[0-9]{1,3}){3}(?:\/(?:[0-9]|[12][0-9]|3[0-2]))?|[0-9A-Fa-f]{0,4}(?::[0-9A-Fa-f]{0,4}){2,7}(?:\/[0-9]{1,3})?)\z/D';
    private const string FW_FROM = '/\A(?:any|[0-9]{1,3}(?:\.[0-9]{1,3}){3}(?:\/(?:[0-9]|[12][0-9]|3[0-2]))?|[0-9A-Fa-f]{0,4}(?::[0-9A-Fa-f]{0,4}){2,7}(?:\/[0-9]{1,3})?)\z/D';
    private const string FW_RULE_ID = '/\A[a-f0-9]{8}\z/D';
    private const string TTL = '/\A(?:0|[1-9][0-9]{1,7})\z/D';
    // Who reaches the panel: `any`, or a list of addresses and networks (panel:access, the list's one argument).
    private const string PANEL_ALLOW = '/\A(?:any|[0-9]{1,3}(?:\.[0-9]{1,3}){3}(?:\/(?:[0-9]|[12][0-9]|3[0-2]))?|[0-9A-Fa-f]{0,4}(?::[0-9A-Fa-f]{0,4}){2,7}(?:\/[0-9]{1,3})?)\z/D';
    /**
     * The arguments the gateway takes as a list: the addresses that reach the panel (panel:access), up to 50.
     */
    private const array LIST_ARGUMENTS = ['panel:access' => ['allow' => 50]];

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
            'hsts' => self::YES_NO, 'staging' => self::YES_NO, 'challenge' => self::CHALLENGE, 'wildcard' => self::YES_NO],
            'required' => ['domain', 'email'], 'since' => '1.21.0', 'kind' => self::ACTION],
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
        // itself unless it is kept in time with change:confirm (1.35.0).
        'service:list' => ['args' => [], 'required' => [], 'since' => '1.33.0', 'kind' => self::READ],
        'service:restart' => ['args' => ['name' => self::SERVICE], 'required' => ['name'], 'since' => '1.33.0', 'kind' => self::ACTION],
        'service:reload' => ['args' => ['name' => self::SERVICE], 'required' => ['name'], 'since' => '1.33.0', 'kind' => self::ACTION],
        'service:log' => ['args' => ['name' => self::SERVICE, 'lines' => self::LINES], 'required' => ['name'], 'since' => '1.33.0', 'kind' => self::READ],
        'self:profiles' => ['args' => [], 'required' => [], 'since' => '1.33.0', 'kind' => self::READ],
        'cron:list' => ['args' => ['domain' => self::DOMAIN], 'required' => ['domain'], 'since' => '1.34.0', 'kind' => self::READ],
        'cron:job-add' => ['args' => ['domain' => self::DOMAIN, 'schedule' => self::CRON_SCHEDULE, 'command' => self::CRON_COMMAND],
            'required' => ['domain', 'schedule', 'command'], 'since' => '1.34.0', 'kind' => self::ACTION],
        'cron:job-remove' => ['args' => ['id' => self::CRON_ID], 'required' => ['id'], 'since' => '1.34.0', 'kind' => self::ACTION],
        'cron:job-enable' => ['args' => ['id' => self::CRON_ID], 'required' => ['id'], 'since' => '1.34.0', 'kind' => self::ACTION],
        'cron:job-disable' => ['args' => ['id' => self::CRON_ID], 'required' => ['id'], 'since' => '1.34.0', 'kind' => self::ACTION],
        'cron:job-runs' => ['args' => ['id' => self::CRON_ID, 'limit' => self::CRON_LIMIT], 'required' => ['id'], 'since' => '1.34.0', 'kind' => self::READ],
        'firewall:status' => ['args' => [], 'required' => [], 'since' => '1.35.0', 'kind' => self::READ],
        'firewall:rule-add' => ['args' => ['port' => self::FW_PORT, 'proto' => self::FW_PROTO, 'from' => self::FW_FROM, 'action' => self::FW_ACTION],
            'required' => ['port', 'proto'], 'since' => '1.35.0', 'kind' => self::ACTION],
        'firewall:rule-remove' => ['args' => ['id' => self::FW_RULE_ID], 'required' => ['id'], 'since' => '1.35.0', 'kind' => self::ACTION],
        'firewall:block' => ['args' => ['address' => self::ADDRESS, 'ttl' => self::TTL], 'required' => ['address'], 'since' => '1.35.0', 'kind' => self::ACTION],
        'firewall:unblock' => ['args' => ['address' => self::ADDRESS], 'required' => ['address'], 'since' => '1.35.0', 'kind' => self::ACTION],
        'panel:access' => ['args' => ['allow' => self::PANEL_ALLOW], 'required' => ['allow'], 'since' => '1.35.0', 'kind' => self::ACTION],
        // Files of a site, as its own user and inside its root only (simai-env, after 1.35.0; Panel plan 6.1, 6.2).
        // A site's files, as its own user inside its folder, links never followed (simai-env 1.39.0); an upload, a packed
        // folder and an unpacked archive came in 1.40.0. There is no trash: a removal is final.
        'file:list' => ['args' => ['domain' => self::DOMAIN, 'path' => self::FS_PATH], 'required' => ['domain'], 'since' => '1.39.0', 'kind' => self::READ],
        'file:stat' => ['args' => ['domain' => self::DOMAIN, 'path' => self::FS_PATH], 'required' => ['domain', 'path'], 'since' => '1.39.0', 'kind' => self::READ],
        'file:read' => ['args' => ['domain' => self::DOMAIN, 'path' => self::FS_PATH], 'required' => ['domain', 'path'], 'since' => '1.39.0', 'kind' => self::READ],
        'file:download' => ['args' => ['domain' => self::DOMAIN, 'path' => self::FS_PATH], 'required' => ['domain', 'path'], 'since' => '1.39.0', 'kind' => self::READ],
        'file:write' => ['args' => ['domain' => self::DOMAIN, 'path' => self::FS_PATH, 'content' => self::FILE_CONTENT, 'expect-sha256' => self::EXPECT_SHA256],
            'required' => ['domain', 'path', 'content'], 'since' => '1.39.0', 'kind' => self::ACTION],
        'file:mkdir' => ['args' => ['domain' => self::DOMAIN, 'path' => self::FS_PATH], 'required' => ['domain', 'path'], 'since' => '1.39.0', 'kind' => self::ACTION],
        'file:rename' => ['args' => ['domain' => self::DOMAIN, 'path' => self::FS_PATH, 'to' => self::FS_PATH], 'required' => ['domain', 'path', 'to'], 'since' => '1.39.0', 'kind' => self::ACTION],
        'file:remove' => ['args' => ['domain' => self::DOMAIN, 'path' => self::FS_PATH], 'required' => ['domain', 'path'], 'since' => '1.39.0', 'kind' => self::ACTION],
        'file:upload' => ['args' => ['domain' => self::DOMAIN, 'path' => self::FS_PATH, 'expect-sha256' => self::EXPECT_SHA256], 'required' => ['domain', 'path'], 'since' => '1.40.0', 'kind' => self::ACTION],
        'file:archive' => ['args' => ['domain' => self::DOMAIN, 'path' => self::FS_PATH, 'to' => self::FS_ARCHIVE], 'required' => ['domain', 'path', 'to'], 'since' => '1.40.0', 'kind' => self::ACTION],
        'file:extract' => ['args' => ['domain' => self::DOMAIN, 'path' => self::FS_PATH, 'to' => self::FS_PATH, 'overwrite' => self::YES_NO], 'required' => ['domain', 'path', 'to'], 'since' => '1.40.0', 'kind' => self::ACTION],
        // The site's own nginx directives, the system level's (simai-env 1.41.0): a timed change, applied once nginx takes
        // them and the site answers, then undone after two minutes unless change:confirm keeps it. Empty removes them.
        'site:nginx-custom-show' => ['args' => ['domain' => self::DOMAIN], 'required' => ['domain'], 'since' => '1.41.0', 'kind' => self::READ],
        'site:nginx-custom' => ['args' => ['domain' => self::DOMAIN, 'content' => self::FILE_CONTENT], 'required' => ['domain', 'content'], 'since' => '1.41.0', 'kind' => self::ACTION],
        // Apache behind nginx for a PHP site that relies on .htaccess (simai-env 1.42.0), the system level's.
        'site:apache' => ['args' => ['domain' => self::DOMAIN, 'enable' => self::YES_NO], 'required' => ['domain', 'enable'], 'since' => '1.42.0', 'kind' => self::ACTION],
        // A site's names besides its domain, www and the others (simai-env 1.53.0, ENV's contract of 2026-11-07): read,
        // added and removed; a wildcard name is chosen when the site is made and is only read here.
        'site:names' => ['args' => ['domain' => self::DOMAIN], 'required' => ['domain'], 'since' => '1.53.0', 'kind' => self::READ],
        'site:name-add' => ['args' => ['domain' => self::DOMAIN, 'name' => self::DOMAIN, 'mode' => self::NAME_MODE, 'check-dns' => self::YES_NO],
            'required' => ['domain', 'name'], 'since' => '1.53.0', 'kind' => self::ACTION],
        'site:name-remove' => ['args' => ['domain' => self::DOMAIN, 'name' => self::DOMAIN], 'required' => ['domain', 'name'], 'since' => '1.53.0', 'kind' => self::ACTION],
        // A profile rule of the site's check (simai-env 1.55.1): its state alone, and the fix the server offers for it,
        // planned and confirmed as every action; refused with unknown_check or, for a rule that passes, check_ok.
        'site:check-state' => ['args' => ['domain' => self::DOMAIN, 'id' => self::CHECK_ID], 'required' => ['domain', 'id'], 'since' => '1.55.1', 'kind' => self::READ],
        'site:check-fix' => ['args' => ['domain' => self::DOMAIN, 'id' => self::CHECK_ID], 'required' => ['domain', 'id'], 'since' => '1.55.1', 'kind' => self::ACTION],
        // A site's creation route (simai-env 1.55.0-1.56.0): where the site stands on it (site:status, the same for every
        // kind), the next step of each route, each planned and confirmed as every action, and WordPress's own state.
        'site:status' => ['args' => ['domain' => self::DOMAIN], 'required' => ['domain'], 'since' => '1.56.0', 'kind' => self::READ],
        'bitrix:restore-ready' => ['args' => ['domain' => self::DOMAIN, 'archive' => self::BITRIX_ARCHIVE, 'source' => self::SITE_FOLDER, 'url' => self::ARCHIVE_URL],
            'required' => ['domain'], 'since' => '1.55.0', 'kind' => self::ACTION],
        'bitrix:finalize' => ['args' => ['domain' => self::DOMAIN], 'required' => ['domain'], 'since' => '1.55.0', 'kind' => self::ACTION],
        'wp:status' => ['args' => ['domain' => self::DOMAIN], 'required' => ['domain'], 'since' => '1.56.0', 'kind' => self::READ],
        'wp:install' => ['args' => ['domain' => self::DOMAIN, 'title' => self::WP_TITLE, 'admin-user' => self::WP_USER, 'admin-email' => self::EMAIL, 'locale' => self::WP_LOCALE],
            'required' => ['domain', 'title', 'admin-user', 'admin-email'], 'since' => '1.56.0', 'kind' => self::ACTION],
        'wp:restore-ready' => ['args' => ['domain' => self::DOMAIN, 'files' => self::SITE_FOLDER, 'dump' => self::SITE_FOLDER, 'overwrite' => self::YES_NO],
            'required' => ['domain', 'files', 'dump'], 'since' => '1.56.0', 'kind' => self::ACTION],
        'wp:finalize' => ['args' => ['domain' => self::DOMAIN], 'required' => ['domain'], 'since' => '1.56.0', 'kind' => self::ACTION],
        'laravel:finalize' => ['args' => ['domain' => self::DOMAIN, 'migrate' => self::YES_NO], 'required' => ['domain'], 'since' => '1.56.0', 'kind' => self::ACTION],
        // A release deployed from Git, the deploy step of the Laravel repository route (simai-env 1.56.2's route; the gateway
        // has allowed it since 1.22.0). The gateway takes a git ref or an attached archive; the panel sends no archive yet,
        // so it asks the repository and its ref both, as the gateway wants them together.
        'site:deploy' => ['args' => ['domain' => self::DOMAIN, 'git' => self::GIT_URL, 'ref' => self::GIT_REF], 'required' => ['domain', 'git', 'ref'],
            'since' => '1.22.0', 'kind' => self::ACTION],
        // A timed change kept by the person who made it (the firewall, the panel's access, nginx directives): without the
        // confirmation in time, the server puts the previous one back by itself.
        'change:confirm' => ['args' => ['change_id' => self::CHANGE_ID], 'required' => ['change_id'], 'since' => '1.35.0', 'kind' => self::ACTION],
        'change:status' => ['args' => ['change_id' => self::CHANGE_ID], 'required' => ['change_id'], 'since' => '1.35.0', 'kind' => self::READ],
        // The token the server keeps for DNS-01 certificates, the administrator's alone, and whether one is kept (1.36.0).
        'ssl:dns-credentials' => ['args' => ['provider' => self::DNS_PROVIDER, 'token' => self::CF_TOKEN, 'remove' => self::YES_NO],
            'required' => ['provider'], 'since' => '1.36.0', 'kind' => self::ACTION],
        'ssl:dns-status' => ['args' => [], 'required' => [], 'since' => '1.36.0', 'kind' => self::READ],
        // The server's PHP versions, the administrator's (simai-env 1.37.0): a new version installed next to the others and
        // touching no site, a free one removed, an extension turned on or off, fixes within a version with its sites checked.
        'php:list' => ['args' => [], 'required' => [], 'since' => '1.37.0', 'kind' => self::READ],
        'php:install' => ['args' => ['php' => self::PHP, 'include-common' => self::YES_NO], 'required' => ['php'], 'since' => '1.37.0', 'kind' => self::ACTION],
        'php:remove' => ['args' => ['php' => self::PHP], 'required' => ['php'], 'since' => '1.37.0', 'kind' => self::ACTION],
        'php:extension' => ['args' => ['php' => self::PHP, 'name' => self::PHP_EXT, 'enabled' => self::YES_NO], 'required' => ['php', 'name', 'enabled'], 'since' => '1.37.0', 'kind' => self::ACTION],
        'php:update' => ['args' => ['php' => self::PHP], 'required' => ['php'], 'since' => '1.37.0', 'kind' => self::ACTION],
        // The server's software and the system's updates, the system level's (simai-env 1.38.0); an update never reboots.
        'software:list' => ['args' => [], 'required' => [], 'since' => '1.38.0', 'kind' => self::READ],
        'software:refresh' => ['args' => [], 'required' => [], 'since' => '1.38.0', 'kind' => self::ACTION],
        'software:update' => ['args' => ['security-only' => self::YES_NO], 'required' => [], 'since' => '1.38.0', 'kind' => self::ACTION],
        'software:install' => ['args' => ['name' => self::SOFTWARE_NAME, 'version' => self::MAJOR], 'required' => ['name'], 'since' => '1.38.0', 'kind' => self::ACTION],
        // A program taken away, its configuration and data kept; refused while sites need it (simai-env 1.43.0).
        'software:remove' => ['args' => ['name' => self::SOFTWARE_NAME], 'required' => ['name'], 'since' => '1.43.0', 'kind' => self::ACTION],
        // The server's security (simai-env 1.45.0–1.47.0): its checks and score, putting a problem right, the sites' files
        // checked for malicious code and moved into quarantine, the protection level and the limit on requests.
        'security:status' => ['args' => [], 'required' => [], 'since' => '1.45.0', 'kind' => self::READ],
        'security:scan' => ['args' => [], 'required' => [], 'since' => '1.45.0', 'kind' => self::ACTION],
        'security:fix' => ['args' => ['check' => self::SECURITY_FIX], 'required' => ['check'], 'since' => '1.45.0', 'kind' => self::ACTION],
        'self:firewall-ensure' => ['args' => [], 'required' => [], 'since' => '1.45.0', 'kind' => self::ACTION],
        'security:files' => ['args' => [], 'required' => [], 'since' => '1.46.0', 'kind' => self::READ],
        'security:files-scan' => ['args' => ['domain' => self::DOMAIN], 'required' => [], 'since' => '1.46.0', 'kind' => self::ACTION],
        'security:quarantine' => ['args' => ['domain' => self::DOMAIN, 'path' => self::FS_PATH], 'required' => ['domain', 'path'], 'since' => '1.46.0', 'kind' => self::ACTION],
        'security:quarantine-list' => ['args' => [], 'required' => [], 'since' => '1.46.0', 'kind' => self::READ],
        'security:quarantine-restore' => ['args' => ['id' => self::CHANGE_ID], 'required' => ['id'], 'since' => '1.46.0', 'kind' => self::ACTION],
        'security:level' => ['args' => ['set' => self::SECURITY_LEVEL], 'required' => ['set'], 'since' => '1.47.0', 'kind' => self::ACTION],
        'security:geo' => ['args' => ['mode' => self::GEO_MODE, 'countries' => self::GEO_COUNTRIES, 'ports' => self::GEO_PORTS, 'keep' => self::GEO_KEEP],
            'required' => ['mode'], 'since' => '1.48.0', 'kind' => self::ACTION],
        'security:geo-status' => ['args' => [], 'required' => [], 'since' => '1.48.0', 'kind' => self::READ],
        'security:geo-update' => ['args' => [], 'required' => [], 'since' => '1.48.0', 'kind' => self::ACTION],
        'security:geo-lookup' => ['args' => ['ip' => self::IP], 'required' => ['ip'], 'since' => '1.48.0', 'kind' => self::READ],
        // Access for AI assistants over MCP (simai-env 1.49.0), administrators only: a key for the whole server or for
        // one owner's sites, removed by its fingerprint or with all of an owner's.
        // Where the panel answers (simai-env 1.51.0), administrators only: its port and its address by IP are timed changes
        // kept from the new address (change:confirm), a domain gets Let's Encrypt or nothing is left of it.
        'panel:address' => ['args' => [], 'required' => [], 'since' => '1.51.0', 'kind' => self::READ],
        'panel:port' => ['args' => ['port' => self::PORT], 'required' => ['port'], 'since' => '1.51.0', 'kind' => self::ACTION],
        'panel:ip-access' => ['args' => ['enabled' => self::YES_NO], 'required' => ['enabled'], 'since' => '1.51.0', 'kind' => self::ACTION],
        'panel:domain' => ['args' => ['domain' => self::DOMAIN, 'email' => self::EMAIL, 'remove' => self::YES_NO], 'required' => [], 'since' => '1.51.0', 'kind' => self::ACTION],
        // Backups away from the server (simai-env 1.44.0), administrators only: S3 storages, their copies and fetching one back.
        'backup:storage-list' => ['args' => [], 'required' => [], 'since' => '1.44.0', 'kind' => self::READ],
        'backup:storage-add' => ['args' => ['name' => self::STORAGE, 'bucket' => self::BUCKET, 'endpoint' => self::ORIGIN, 'region' => self::REGION,
            'prefix' => self::PREFIX, 'keep' => self::KEEP_DAYS, 'default' => self::YES_NO, 'access-key' => self::S3_KEY, 'secret-key' => self::S3_KEY],
            'required' => ['name', 'bucket', 'access-key', 'secret-key'], 'since' => '1.44.0', 'kind' => self::ACTION],
        'backup:storage-test' => ['args' => ['name' => self::STORAGE], 'required' => ['name'], 'since' => '1.44.0', 'kind' => self::ACTION],
        'backup:storage-remove' => ['args' => ['name' => self::STORAGE], 'required' => ['name'], 'since' => '1.44.0', 'kind' => self::ACTION],
        'backup:storage-copies' => ['args' => ['name' => self::STORAGE, 'domain' => self::DOMAIN], 'required' => ['name'], 'since' => '1.44.0', 'kind' => self::READ],
        'backup:storage-fetch' => ['args' => ['name' => self::STORAGE, 'domain' => self::DOMAIN, 'backup' => self::STAMP], 'required' => ['name', 'domain', 'backup'],
            'since' => '1.44.0', 'kind' => self::ACTION],
        'mcp:status' => ['args' => [], 'required' => [], 'since' => '1.49.0', 'kind' => self::READ],
        'mcp:enable' => ['args' => [], 'required' => [], 'since' => '1.49.0', 'kind' => self::ACTION],
        'mcp:disable' => ['args' => [], 'required' => [], 'since' => '1.49.0', 'kind' => self::ACTION],
        'mcp:key-add' => ['args' => ['pubkey' => self::PUBKEY, 'owner' => self::OWNER, 'admin' => self::YES], 'required' => ['pubkey'], 'since' => '1.49.0', 'kind' => self::ACTION],
        'mcp:key-remove' => ['args' => ['fingerprint' => self::FINGERPRINT, 'owner' => self::OWNER], 'required' => [], 'since' => '1.49.0', 'kind' => self::ACTION],
        'security:web-protect' => ['args' => ['enabled' => self::YES_NO, 'rate' => self::WEB_RATE, 'burst' => self::WEB_BURST], 'required' => ['enabled'], 'since' => '1.47.0', 'kind' => self::ACTION],
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
        // A Bitrix backup already on the server or at an https address (simai-env 1.56.0).
        'bitrix:restore-ready' => ['source' => '1.56.0', 'url' => '1.56.0'],
        // A certificate through Cloudflare DNS, also for every subdomain (simai-env 1.36.0).
        'ssl:letsencrypt' => ['challenge' => '1.36.0', 'wildcard' => '1.36.0'],
    ];

    /**
     * Commands whose content travels as an attachment after the request line ({sha256, size}, then exactly size bytes),
     * as site:deploy's release does; a plan carries only the metadata. With the most each one takes.
     */
    private const array ATTACHMENTS = [
        'file:upload' => 1_073_741_824,
    ];

    /** A download answers a JSON line and then exactly `size` bytes: the most the panel takes. */
    public const int DOWNLOAD_MAX_BYTES = 1_073_741_824;

    private function __construct()
    {
    }

    /** The most bytes the command's attachment may have; null for a command that takes none. */
    public static function attachmentLimit(string $command): ?int
    {
        return self::ATTACHMENTS[$command] ?? null;
    }

    /** Whether the command's answer is followed by the file's bytes (file:download): it is never asked the usual way. */
    public static function streams(string $command): bool
    {
        return $command === 'file:download';
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

    /** Whether the text is a profile rule's id as the gateway takes it: a fix is offered only for one that would leave. */
    public static function isCheckId(string $id): bool
    {
        return preg_match(self::CHECK_ID, $id) === 1;
    }

    /** Whether the command takes the argument at all (a route's input belongs to the step whose command takes it). */
    public static function accepts(string $command, string $argument): bool
    {
        return isset(self::COMMANDS[$command]['args'][$argument]);
    }

    /** Whether one value of one argument is one the gateway would take, apart from the command's other arguments. */
    public static function valid(string $command, string $argument, string $value): bool
    {
        $pattern = self::COMMANDS[$command]['args'][$argument] ?? null;

        return $pattern !== null && preg_match($pattern, $value) === 1;
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
