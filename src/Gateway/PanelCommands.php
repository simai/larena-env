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
    private const string PROFILE = '/\A(?:static|generic|laravel|wordpress|node|redirect)\z/D';
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
            'redirect-path' => self::KEEP_DROP], 'required' => ['domain'], 'since' => '1.22.0', 'kind' => self::ACTION],
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
        'backup:data-schedule' => ['args' => ['domain' => self::DOMAIN, 'enabled' => self::YES_NO, 'time' => self::TIME, 'keep' => self::KEEP],
            'required' => ['domain', 'enabled'], 'since' => '1.28.0', 'kind' => self::ACTION],
        'backup:data-verify' => ['args' => ['domain' => self::DOMAIN, 'backup' => self::BACKUP], 'required' => ['domain', 'backup'], 'since' => '1.28.0', 'kind' => self::ACTION],
        'php:extensions' => ['args' => ['php' => self::PHP], 'required' => ['php'], 'since' => '1.28.0', 'kind' => self::READ],
        'site:php-ini-list' => ['args' => ['domain' => self::DOMAIN], 'required' => ['domain'], 'since' => '1.28.0', 'kind' => self::READ],
        'site:php-ini-set' => ['args' => ['domain' => self::DOMAIN, 'name' => self::INI_KEY, 'value' => self::INI_VALUE],
            'required' => ['domain', 'name', 'value'], 'since' => '1.28.0', 'kind' => self::ACTION],
        'site:php-ini-unset' => ['args' => ['domain' => self::DOMAIN, 'name' => self::INI_KEY], 'required' => ['domain', 'name'], 'since' => '1.28.0', 'kind' => self::ACTION],
        'self:metrics' => ['args' => [], 'required' => [], 'since' => '1.28.0', 'kind' => self::READ],
        // SFTP is not in a release yet: offered only when the server lists it.
        'access:list' => ['args' => ['domain' => self::DOMAIN], 'required' => [], 'since' => null, 'kind' => self::READ],
        'access:create-project' => ['args' => ['domain' => self::DOMAIN, 'login' => self::LOGIN, 'pubkey' => self::PUBKEY],
            'required' => ['domain', 'login', 'pubkey'], 'since' => null, 'kind' => self::ACTION],
        'access:add-key' => ['args' => ['login' => self::LOGIN, 'pubkey' => self::PUBKEY], 'required' => ['login', 'pubkey'], 'since' => null, 'kind' => self::ACTION],
        'access:disable' => ['args' => ['login' => self::LOGIN], 'required' => ['login'], 'since' => null, 'kind' => self::ACTION],
        'access:enable' => ['args' => ['login' => self::LOGIN], 'required' => ['login'], 'since' => null, 'kind' => self::ACTION],
        'access:remove' => ['args' => ['login' => self::LOGIN], 'required' => ['login'], 'since' => null, 'kind' => self::ACTION],
        'db:list' => ['args' => [], 'required' => [], 'since' => '1.28.0', 'kind' => self::READ],
        'site:db-rotate' => ['args' => ['domain' => self::DOMAIN], 'required' => ['domain'], 'since' => '1.28.0', 'kind' => self::ACTION],
        'job:status' => ['args' => ['job_id' => self::JOB_ID], 'required' => ['job_id'], 'since' => '1.21.0', 'kind' => self::JOB],
        'job:log' => ['args' => ['job_id' => self::JOB_ID, 'after' => self::CURSOR], 'required' => ['job_id'], 'since' => '1.21.0', 'kind' => self::JOB],
        'job:cancel' => ['args' => ['job_id' => self::JOB_ID], 'required' => ['job_id'], 'since' => '1.21.0', 'kind' => self::JOB],
        'job:list' => ['args' => [], 'required' => [], 'since' => '1.21.0', 'kind' => self::JOB],
    ];

    private function __construct()
    {
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
     * @param array<array-key, mixed> $args
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
            if ($pattern === null || !is_string($value) || preg_match($pattern, $value) !== 1) {
                throw new PanelRefused(PanelReason::INVALID_ARGUMENT);
            }
        }
        foreach ($rules['required'] as $name) {
            if (!array_key_exists($name, $args)) {
                throw new PanelRefused(PanelReason::INVALID_ARGUMENT);
            }
        }
    }
}
