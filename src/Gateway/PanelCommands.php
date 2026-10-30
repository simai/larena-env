<?php

declare(strict_types=1);

namespace Larena\Env\Gateway;

/**
 * The commands this package may ask for, with the gateway's argument rules. Only reads: an action needs a plan,
 * a confirmation and a job, and none of them exists here yet. The gateway checks everything again on its side.
 */
final class PanelCommands
{
    private const string DOMAIN = '/\A(?=.{1,253}\z)[A-Za-z0-9](?:[A-Za-z0-9-]{0,62}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,62}[A-Za-z0-9])?)+\z/D';
    private const string YES_NO = '/\A(?:yes|no)\z/D';

    /** @var array<string, array{args: array<string, string>, required: list<string>}> */
    private const array READ = [
        'self:describe' => ['args' => [], 'required' => []],
        'self:commands' => ['args' => [], 'required' => []],
        'site:describe' => ['args' => ['domain' => self::DOMAIN], 'required' => ['domain']],
        'site:doctor' => ['args' => ['domain' => self::DOMAIN, 'strict' => self::YES_NO, 'include-target' => self::YES_NO], 'required' => ['domain']],
    ];

    private function __construct()
    {
    }

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::READ);
    }

    /**
     * @param array<array-key, mixed> $args
     *
     * @throws PanelRefused
     */
    public static function check(string $command, array $args): void
    {
        $rules = self::READ[$command] ?? null;
        if ($rules === null) {
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
