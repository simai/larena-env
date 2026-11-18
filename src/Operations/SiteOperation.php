<?php

declare(strict_types=1);

namespace Larena\Env\Operations;

use Larena\Env\Gateway\PanelCapabilities;
use Larena\Env\Server\SftpAccount;
use Larena\Env\Server\SiteDetails;

/**
 * The actions a site's screen offers, each one a gateway command with the arguments the screen fills in. The codes are
 * the panel's own words in its addresses and forms; the command and its arguments stay the gateway's.
 */
enum SiteOperation: string
{
    case CertificateIssue = 'certificate-issue';
    case CertificateRenew = 'certificate-renew';
    case Backup = 'backup';
    // A restore is two actions with their own words: the database (after the server backs up the current one) or the
    // files, unpacked beside the site for review; the live files are never replaced.
    case BackupRestoreDatabase = 'backup-restore-database';
    case BackupExtractFiles = 'backup-extract-files';
    case BackupVerify = 'backup-verify';
    case BackupSchedule = 'backup-schedule';
    case Rollback = 'rollback';
    case ServiceRestart = 'service-restart';
    case QueueRestart = 'queue-restart';
    case PhpVersion = 'php-version';
    case PhpSetting = 'php-setting';
    case PhpSettingRemove = 'php-setting-remove';
    case DatabaseCreate = 'database-create';
    case DatabasePassword = 'database-password';
    case DatabaseExport = 'database-export';
    case SftpCreate = 'sftp-create';
    case SftpAddKey = 'sftp-add-key';
    case SftpDisable = 'sftp-disable';
    case SftpEnable = 'sftp-enable';
    case SftpRemove = 'sftp-remove';
    // Apache behind nginx for .htaccess, or nginx alone again (simai-env 1.42.0).
    case ApacheOn = 'apache-on';
    case ApacheOff = 'apache-off';
    // A site's extra name, such as www, added or removed (simai-env 1.53.0).
    case NameAdd = 'name-add';
    case NameRemove = 'name-remove';
    // The fix the server offers for a profile rule of the site's check that did not pass (simai-env 1.55.1).
    case CheckFix = 'check-fix';
    // The next step of a site's creation route (simai-env 1.55.0-1.56.0), offered by the site's «Создание сайта» block.
    case BitrixRestoreReady = 'bitrix-restore-ready';
    case BitrixFinalize = 'bitrix-finalize';
    case WordpressInstall = 'wordpress-install';
    case WordpressRestoreReady = 'wordpress-restore-ready';
    case WordpressFinalize = 'wordpress-finalize';
    case LaravelFinalize = 'laravel-finalize';
    // The deploy step of the Laravel repository route (simai-env 1.56.2): a release from Git, the repository and its ref as
    // the person gave them when the site was made. A release archive (the static repository route) the panel does not send.
    case SiteDeploy = 'deploy';
    // What a site's card can take away (audit block 5, A2): the site itself, with its files and database by choice, its
    // database alone, and a redirect of one of its paths, added or removed.
    case SiteRemove = 'site-remove';
    case DatabaseDrop = 'database-drop';
    case RedirectAdd = 'redirect-add';
    case RedirectRemove = 'redirect-remove';
    // A database browser for the site (simai-env 1.58.0): Adminer on its HTTPS address for a while, and closing it early.
    case DatabaseBrowse = 'database-browse';
    case DatabaseBrowseClose = 'database-browse-close';
    // The site's database connection details (simai-env 1.59.0): the password handed out once, never kept by the panel.
    case DatabaseCredentials = 'database-credentials';
    // Cheap actions on the server's own commands (FASTPANEL comparison 2026-11-18): a site paused and resumed, its caches
    // cleared (simai-env 1.57.0), its certificate taken off and deleted, and a plain PHP site restored from a files archive
    // and an optional dump uploaded into the site (simai-env 1.57.1).
    case SitePause = 'site-pause';
    case SiteResume = 'site-resume';
    case CacheClear = 'cache-clear';
    case CertificateRemove = 'certificate-remove';
    case PhpRestore = 'php-restore';
    // «Передать сайт…» (roles study, second wave): the site handed to another server account, the whole server's action
    // (simai-env 1.57.0 site:transfer). The same action hands it back; a site never goes back to no account.
    case SiteTransfer = 'site-transfer';

    public function command(): string
    {
        return match ($this) {
            self::CertificateIssue => 'ssl:letsencrypt',
            self::CertificateRenew => 'ssl:renew',
            self::Backup => 'backup:data',
            self::BackupRestoreDatabase, self::BackupExtractFiles => 'backup:data-restore',
            self::BackupVerify => 'backup:data-verify',
            self::BackupSchedule => 'backup:data-schedule',
            self::Rollback => 'site:deploy-rollback',
            self::ServiceRestart => 'site:service-restart',
            self::QueueRestart => 'queue:restart',
            self::PhpVersion => 'site:set-php',
            self::PhpSetting => 'site:php-ini-set',
            self::PhpSettingRemove => 'site:php-ini-unset',
            self::DatabaseCreate => 'site:db-create',
            self::DatabasePassword => 'site:db-rotate',
            // A database dump to keep is a backup without the files (simai-env 1.28.0).
            self::DatabaseExport => 'backup:data',
            self::SftpCreate => 'access:create-project',
            self::SftpAddKey => 'access:add-key',
            self::SftpDisable => 'access:disable',
            self::SftpEnable => 'access:enable',
            self::SftpRemove => 'access:remove',
            self::ApacheOn, self::ApacheOff => 'site:apache',
            self::NameAdd => 'site:name-add',
            self::NameRemove => 'site:name-remove',
            self::CheckFix => 'site:check-fix',
            self::BitrixRestoreReady => 'bitrix:restore-ready',
            self::BitrixFinalize => 'bitrix:finalize',
            self::WordpressInstall => 'wp:install',
            self::WordpressRestoreReady => 'wp:restore-ready',
            self::WordpressFinalize => 'wp:finalize',
            self::LaravelFinalize => 'laravel:finalize',
            self::SiteDeploy => 'site:deploy',
            self::SiteRemove => 'site:remove',
            self::DatabaseDrop => 'site:db-drop',
            self::RedirectAdd => 'site:redirect-add',
            self::RedirectRemove => 'site:redirect-remove',
            self::DatabaseBrowse => 'site:db-browser',
            self::DatabaseBrowseClose => 'site:db-browser-close',
            self::DatabaseCredentials => 'site:db-credentials',
            self::SitePause => 'site:suspend',
            self::SiteResume => 'site:resume',
            self::CacheClear => 'site:cache-clear',
            self::CertificateRemove => 'ssl:remove',
            self::PhpRestore => 'site:restore-ready',
            self::SiteTransfer => 'site:transfer',
        };
    }

    /** The tab of the site's card the action stands on. */
    public function tab(): string
    {
        return match ($this) {
            self::CertificateIssue, self::CertificateRenew, self::CertificateRemove, self::NameAdd, self::NameRemove, self::RedirectAdd, self::RedirectRemove => 'domains',
            self::PhpVersion, self::PhpSetting, self::PhpSettingRemove, self::ServiceRestart, self::QueueRestart, self::ApacheOn, self::ApacheOff => 'php',
            self::DatabaseCreate, self::DatabasePassword, self::DatabaseExport, self::DatabaseDrop, self::DatabaseBrowse, self::DatabaseBrowseClose,
            self::DatabaseCredentials => 'database',
            self::Backup, self::BackupRestoreDatabase, self::BackupExtractFiles, self::BackupVerify, self::BackupSchedule => 'backups',
            self::Rollback => 'releases',
            self::SftpCreate, self::SftpAddKey, self::SftpDisable, self::SftpEnable, self::SftpRemove => 'access',
            self::CheckFix, self::BitrixRestoreReady, self::BitrixFinalize, self::WordpressInstall, self::WordpressRestoreReady,
            self::WordpressFinalize, self::LaravelFinalize, self::SiteDeploy, self::SiteRemove,
            self::SitePause, self::SiteResume, self::CacheClear, self::PhpRestore, self::SiteTransfer => 'overview',
        };
    }

    /**
     * The form fields this action asks for, by name: `email`, `login` and `value` are text, `pubkey` a public key,
     * `php` a version of the server, `backup` a backup of the site, `name` a `php.ini` setting, `time` a time of day,
     * `keep` a number of backups, and `db`, `files`, `enabled` yes or no.
     *
     * @return list<string>
     */
    public function fields(): array
    {
        return match ($this) {
            // How the certificate's domain is checked, and whether it covers every subdomain, where the server keeps a
            // DNS token (simai-env 1.36.0); see dnsFields().
            self::CertificateIssue => ['email', 'redirect', 'hsts', 'staging', 'challenge', 'wildcard'],
            self::Backup => ['db', 'files'],
            self::BackupRestoreDatabase, self::BackupExtractFiles => ['backup'],
            self::BackupVerify => ['backup'],
            self::BackupSchedule => ['enabled', 'time', 'keep', 'verify'],
            self::PhpVersion => ['php'],
            self::PhpSetting => ['name', 'value'],
            self::PhpSettingRemove => ['name'],
            self::SftpCreate => ['login', 'pubkey'],
            self::SftpAddKey => ['login', 'pubkey'],
            self::SftpDisable, self::SftpEnable, self::SftpRemove => ['login'],
            // `name` is here a host name of the site, `mode` serve or redirect, `check-dns` yes or no.
            self::NameAdd => ['name', 'mode', 'check-dns'],
            self::NameRemove => ['name'],
            // `id` is the rule's fix id, as the site's check names it.
            self::CheckFix => ['id'],
            // A Bitrix backup already on the server (a folder of the site) or at an https address, never both.
            self::BitrixRestoreReady => ['source', 'url'],
            self::WordpressInstall => ['title', 'admin-user', 'admin-email', 'locale'],
            self::WordpressRestoreReady => ['files', 'dump'],
            self::LaravelFinalize => ['migrate'],
            self::SiteDeploy => ['git', 'ref'],
            // The site's files and its database go only when the person ticks them; unticked, the server keeps them.
            self::SiteRemove => ['remove-files', 'drop-db', 'drop-db-user'],
            // `from` a path of the site, `to` a path of it or another address, `code` the kind of redirect.
            self::RedirectAdd => ['from', 'to', 'code'],
            self::RedirectRemove => ['from'],
            // How long the browser stays open, in minutes: 15, 60 or 240 (the server takes 5 to 480).
            self::DatabaseBrowse => ['minutes'],
            // The files archive and the dump are files of the site (uploaded in «Файлы»); `overwrite` replaces what is there,
            // after the server backs it up.
            self::PhpRestore => ['files', 'dump', 'overwrite'],
            // The server account the site goes to, chosen from the server's accounts other than its own.
            self::SiteTransfer => ['owner'],
            default => [],
        };
    }

    /**
     * The fields this server's gateway takes: an argument a later release added is asked only where the server has it.
     *
     * @return list<string>
     */
    public function fieldsOn(PanelCapabilities $capabilities): array
    {
        return array_values(array_filter($this->fields(), fn (string $field): bool => $capabilities->takes($this->command(), $field)));
    }

    /**
     * The yes-or-no fields, asked as one tick each (the panel's UX audit): the box shows what the server would do
     * anyway, so a person reads a plain choice instead of «как решит сервер»; an unticked box sends no.
     */
    public const array SWITCHES = ['db', 'files', 'redirect', 'hsts', 'staging', 'wildcard', 'enabled', 'verify', 'check-dns', 'migrate',
        'remove-files', 'drop-db', 'drop-db-user', 'overwrite'];

    /**
     * The actions that take something away for good: their button and their confirmation say so (a danger button), and
     * the plan's «Выполнить» asks once more in the Framework question window.
     */
    public const array DESTRUCTIVE = [self::SiteRemove, self::DatabaseDrop, self::CertificateRemove];

    public function destroys(): bool
    {
        return in_array($this, self::DESTRUCTIVE, true);
    }

    /**
     * Whether the action has nothing to fill before its plan (the admin's «Действие с планом» asks its question at once):
     * only ticks, which the question offers as its choices; a certificate too once the person's email is known (its
     * rarer choices keep the server's defaults).
     */
    public function asksNothing(?PanelCapabilities $capabilities = null, bool $emailKnown = false): bool
    {
        $fields = $capabilities === null ? $this->fields() : $this->fieldsOn($capabilities);
        if ($this === self::CertificateIssue) {
            return $emailKnown;
        }

        return array_diff($fields, self::SWITCHES) === [];
    }

    /**
     * The fields chosen from a list or a tick, each with a value the form starts on, or picked from a list the person
     * opens: a form of only these shows its plan under it as it is filled (the admin's «Действие с планом» preview), so
     * «Показать, что будет» is no extra press. Typed fields (a name, a key, an address) wait for «Показать, что будет».
     */
    public const array CHOSEN = ['php', 'backup', 'time', 'keep', 'verify', 'challenge', 'minutes', 'code', 'mode', 'dump', 'owner'];

    /** Whether the action's plan is shown under its form as the form is filled: every field is chosen, none typed. */
    public function plansAsChosen(?PanelCapabilities $capabilities = null): bool
    {
        $fields = $capabilities === null ? $this->fields() : $this->fieldsOn($capabilities);
        foreach ($fields as $field) {
            $listed = $field === 'login' && !$this->namesSite();
            if (!$listed && !in_array($field, self::SWITCHES, true) && !in_array($field, self::CHOSEN, true)) {
                return false;
            }
        }

        return $fields !== [] && !$this->asksNothing($capabilities);
    }

    /** Whether a yes-or-no field starts ticked for this site. */
    public static function switchedOn(string $field, ?\Larena\Env\Server\SiteDetails $site): bool
    {
        return match ($field) {
            'db' => $site?->databaseEngine !== null,
            // A new name is checked in DNS unless the person unticks it: without the check a mistyped name is added too.
            'files', 'redirect', 'enabled', 'check-dns', 'migrate' => true,
            'verify' => $site?->backupSchedule?->verify !== false,
            default => false,
        };
    }

    /**
     * A certificate's rarely needed choices, in the order the form asks them (block 4, texts for a newcomer): they stand
     * together under «Подробнее», all off, the first opening the fold.
     */
    public const array MORE = ['hsts', 'staging', 'challenge', 'wildcard'];

    /** The fields asked only where the server keeps a DNS token for certificates: without one a DNS check fails. */
    public const array DNS_FIELDS = ['challenge', 'wildcard'];

    /**
     * The actions of the site's names: the «Имена сайта» block offers them on its own rows, so the tab's row of action
     * buttons leaves them out.
     */
    public const array NAMES = [self::NameAdd, self::NameRemove];

    /**
     * The database browser's actions: the «База данных» block offers them by its state (open: until when, and «Закрыть»),
     * so the tab's row of action buttons leaves them out.
     */
    public const array BROWSER = [self::DatabaseBrowse, self::DatabaseBrowseClose];

    /**
     * The actions of the «База данных» block itself (simai-env 1.59.0): «Данные для подключения», «Создать базу» for a
     * site without one, «Сменить пароль»; the tab's row of buttons leaves them out with the browser's.
     */
    public const array DATABASE = [self::DatabaseCreate, self::DatabaseCredentials, self::DatabasePassword];

    /** The certificate's removal: the «Сертификат» block offers it under the certificate it takes off, not the tab's row. */
    public const array CERTIFICATE = [self::CertificateRemove];

    /**
     * The PHP version's change: «PHP и веб-сервер» offers it on the «PHP 8.3» line at the tab's top, not in the row at its
     * end (FASTPANEL comparison 2026-11-18: the main action was under forty lines of extensions).
     */
    public const array RUNTIME = [self::PhpVersion];

    /**
     * An SFTP access's own actions: offered on the access's row with the access chosen (roles study PX-71), not among the
     * tab's buttons, where the access had to be picked again in the form.
     */
    public const array PER_ACCESS = [self::SftpAddKey, self::SftpDisable, self::SftpEnable, self::SftpRemove];

    /**
     * The tab's actions its row of buttons offers: not those the «База данных» block offers by its state (the browser,
     * the database's own), nor an account's action without an account to name. The actions that destroy come last, after
     * the ordinary ones (panel audit 2026-11-18: «Удалить сайт» first and red was the first thing the eye met).
     *
     * @param list<self> $operations
     * @param array<string, list<mixed>> $accountChoices the accounts each account's action may name
     *
     * @return list<self>
     */
    public static function inRow(array $operations, array $accountChoices = []): array
    {
        $row = array_values(array_filter($operations, static fn (self $operation): bool => ($operation->namesSite() || ($accountChoices[$operation->value] ?? []) !== [])
            && !in_array($operation, self::PER_ACCESS, true) && !in_array($operation, self::BROWSER, true) && !in_array($operation, self::DATABASE, true) && !in_array($operation, self::CERTIFICATE, true)
            && !in_array($operation, self::RUNTIME, true)));

        return [...array_values(array_filter($row, static fn (self $operation): bool => !$operation->destroys())),
            ...array_values(array_filter($row, static fn (self $operation): bool => $operation->destroys()))];
    }

    /** The fix of a check's rule: the «Проверка» block offers it on the rule's own row, never among the tab's buttons. */
    public const array CHECKS = [self::CheckFix];

    /**
     * The steps of a site's creation route, by the gateway command site:status names as `next`: the «Создание сайта» block
     * offers the one the site stands on, never the tab's row of buttons.
     */
    public const array ROUTE_STEPS = ['bitrix:restore-ready' => self::BitrixRestoreReady, 'bitrix:finalize' => self::BitrixFinalize,
        'wp:install' => self::WordpressInstall, 'wp:restore-ready' => self::WordpressRestoreReady, 'wp:finalize' => self::WordpressFinalize,
        'laravel:finalize' => self::LaravelFinalize, 'site:deploy' => self::SiteDeploy];

    public function isRouteStep(): bool
    {
        return in_array($this, self::ROUTE_STEPS, true);
    }

    /** The actions of the system level alone: a site's owner is not offered them (the server refuses them too). */
    public const array SYSTEM_ONLY = [self::ApacheOn, self::ApacheOff, self::SiteTransfer];

    /**
     * The server accounts a site can be handed to: every account but the one it already belongs to and a blocked one
     * (the server refuses both). Never «no account»: the server cannot give a site back to none.
     *
     * @param list<string> $owners the server's accounts
     * @param list<string> $blocked the blocked ones
     *
     * @return list<string>
     */
    public static function transferChoices(?string $current, array $owners, array $blocked = []): array
    {
        return array_values(array_filter($owners, static fn (string $owner): bool => $owner !== $current && !in_array($owner, $blocked, true)));
    }

    /** An SFTP account's own action names the account, not the site: the gateway checks it belongs to the site's owner. */
    public function namesSite(): bool
    {
        return !in_array($this, [self::SftpAddKey, self::SftpDisable, self::SftpEnable, self::SftpRemove], true);
    }

    /**
     * The site's SFTP accounts this action can be asked for: an account that is on can be turned off, one that is off
     * turned on; a key is added to, or an account removed from, any of them. None for an action that names the site.
     *
     * @param list<SftpAccount> $accounts
     *
     * @return list<SftpAccount>
     */
    public function accountChoices(array $accounts): array
    {
        if ($this->namesSite()) {
            return [];
        }

        return array_values(array_filter($accounts, fn (SftpAccount $account): bool => match ($this) {
            self::SftpDisable => $account->enabled,
            self::SftpEnable => !$account->enabled,
            default => true,
        }));
    }

    /**
     * Whether the site can be asked for this at all, so the screen offers nothing that would only be refused.
     * A site without a certificate has nothing to renew; without a previous release nothing to roll back to;
     * without workers no queue; a site nginx serves without its own service has nothing to restart; a site without
     * PHP has no version or settings; without a database nothing to export; without backups nothing to restore.
     */
    public function suitsSite(SiteDetails $site): bool
    {
        return match ($this) {
            self::CertificateRenew => $site->certificate !== null,
            self::Rollback => $site->previousRelease !== null,
            self::ServiceRestart => $site->service !== null,
            self::QueueRestart => $site->workers !== [],
            self::PhpVersion, self::PhpSetting, self::PhpSettingRemove => $site->php !== 'none',
            // One database per site: offered only to a site without one. Whether its kind may have one is the server's
            // to say (a profile with db=no refuses in the plan, said in words).
            self::DatabaseCreate => $site->databaseEngine === null,
            // simai-env 1.59.0 changes the password in a WordPress or Bitrix site's own config too.
            self::DatabasePassword, self::DatabaseCredentials => $site->databaseEngine !== null,
            self::DatabaseExport => $site->databaseEngine !== null,
            self::DatabaseDrop => $site->databaseEngine !== null,
            // The browser wherever the site has a database; what stops it otherwise, the server says in the plan (1.61.0).
            self::DatabaseBrowse => $site->databaseEngine !== null,
            self::DatabaseBrowseClose => $site->databaseEngine !== null,
            // Apache serves PHP profiles only; the switch offers the other way from what the server says serves the site.
            self::ApacheOn => in_array($site->profile, SiteDetails::APACHE_PROFILES, true) && $site->webServer === 'nginx',
            self::ApacheOff => in_array($site->profile, SiteDetails::APACHE_PROFILES, true) && $site->webServer === 'apache',
            self::BackupRestoreDatabase => $site->backups !== [] && $site->databaseEngine !== null,
            self::BackupExtractFiles, self::BackupVerify => $site->backups !== [],
            // A paused site is resumed, a working one paused; a site that only forwards visitors has nothing to pause or clear.
            self::SitePause => !$site->suspended && !in_array($site->profile, ['redirect', 'alias'], true),
            self::SiteResume => $site->suspended,
            self::CacheClear => !in_array($site->profile, ['redirect', 'alias', 'proxy'], true),
            self::CertificateRemove => $site->certificate !== null,
            // The server restores only a plain PHP site so (Bitrix and WordPress have their own routes).
            self::PhpRestore => $site->profile === 'generic',
            // An alias runs as the site it points to: that site is handed over instead.
            self::SiteTransfer => $site->profile !== 'alias',
            default => true,
        };
    }

    /**
     * The gateway arguments: the site's domain and what the form carries, left as typed. The command table refuses
     * anything the gateway would refuse, so a mistyped address or email is said in words before the request leaves.
     *
     * @param array<string, string> $input
     *
     * @return array<string, string>
     */
    public function arguments(string $domain, array $input): array
    {
        $args = $this->namesSite() ? ['domain' => $domain] : [];
        // What the action itself fixes, whatever the form says.
        $args += match ($this) {
            self::BackupRestoreDatabase => ['db' => 'yes', 'files' => 'no'],
            self::BackupExtractFiles => ['db' => 'no', 'files' => 'yes'],
            self::DatabaseExport => ['db' => 'yes', 'files' => 'no'],
            self::ApacheOn => ['enable' => 'yes'],
            self::ApacheOff => ['enable' => 'no'],
            // «Удалить сертификат» deletes it, not only takes it off the site.
            self::CertificateRemove => ['delete-cert' => 'yes'],
            default => [],
        };
        foreach ($this->fields() as $field) {
            $value = $input[$field] ?? null;
            if (is_string($value) && $value !== '') {
                $args[$field] = $value;
            }
        }

        return $args;
    }

    /**
     * What the site can be asked for on this server: the actions that suit the site and whose command its gateway has.
     *
     * @return list<self>
     */
    public static function offeredFor(SiteDetails $site, ?PanelCapabilities $capabilities = null): array
    {
        $capabilities ??= PanelCapabilities::none();

        return array_values(array_filter(self::cases(),
            static fn (self $operation): bool => !$operation->isRouteStep() && $operation->suitsSite($site) && $capabilities->allows($operation->command())));
    }
}
