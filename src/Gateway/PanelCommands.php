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

    /** @var array<string, array{args: array<string, string>, required: list<string>, kind: string}> */
    private const array COMMANDS = [
        'self:describe' => ['args' => [], 'required' => [], 'kind' => self::READ],
        'self:commands' => ['args' => [], 'required' => [], 'kind' => self::READ],
        'site:describe' => ['args' => ['domain' => self::DOMAIN], 'required' => ['domain'], 'kind' => self::READ],
        'site:doctor' => ['args' => ['domain' => self::DOMAIN, 'strict' => self::YES_NO, 'include-target' => self::YES_NO],
            'required' => ['domain'], 'kind' => self::READ],
        'logs:nginx' => ['args' => ['domain' => self::DOMAIN, 'kind' => self::LOG_KIND, 'lines' => self::LINES],
            'required' => ['domain'], 'kind' => self::READ],
        // Actions of the certificates, backups, releases and services screens (simai-env 1.21.0).
        'ssl:letsencrypt' => ['args' => ['domain' => self::DOMAIN, 'email' => self::EMAIL, 'redirect' => self::YES_NO,
            'hsts' => self::YES_NO, 'staging' => self::YES_NO], 'required' => ['domain', 'email'], 'kind' => self::ACTION],
        'ssl:renew' => ['args' => ['domain' => self::DOMAIN], 'required' => ['domain'], 'kind' => self::ACTION],
        'backup:data' => ['args' => ['domain' => self::DOMAIN, 'db' => self::YES_NO, 'files' => self::YES_NO],
            'required' => ['domain'], 'kind' => self::ACTION],
        'site:deploy-rollback' => ['args' => ['domain' => self::DOMAIN], 'required' => ['domain'], 'kind' => self::ACTION],
        'site:service-restart' => ['args' => ['domain' => self::DOMAIN], 'required' => ['domain'], 'kind' => self::ACTION],
        'queue:restart' => ['args' => ['domain' => self::DOMAIN], 'required' => ['domain'], 'kind' => self::ACTION],
        'job:status' => ['args' => ['job_id' => self::JOB_ID], 'required' => ['job_id'], 'kind' => self::JOB],
        'job:log' => ['args' => ['job_id' => self::JOB_ID, 'after' => self::CURSOR], 'required' => ['job_id'], 'kind' => self::JOB],
        'job:cancel' => ['args' => ['job_id' => self::JOB_ID], 'required' => ['job_id'], 'kind' => self::JOB],
        'job:list' => ['args' => [], 'required' => [], 'kind' => self::JOB],
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
