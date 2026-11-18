<?php

declare(strict_types=1);

namespace Larena\Env\Admin;

/**
 * A plan's values in the panel's words (the panel's UX audit, COPY-02; block 4, texts for a newcomer): each named as its
 * field is on the form or in the plan's own dictionary, yes and no in words, a kind, a database, a language or a way
 * said as the forms say them, and the site left out where the plan already names it. A value the panel has no name for
 * keeps the server's own.
 */
final class PlanValues
{
    /** The values a form of the panel names (`fields.*`); others keep the server's name. */
    public const array NAMED = ['verify', 'email', 'redirect', 'hsts', 'staging', 'challenge', 'wildcard', 'db', 'files', 'php',
        'backup', 'time', 'keep', 'enabled', 'name', 'value', 'login', 'pubkey', 'remove-files', 'drop-db', 'drop-db-user'];

    /**
     * The commands that take something away for good (audit block 5, A2): the plan's «Выполнить» is a danger button and
     * asks once more in the Framework question window.
     */
    public const array DESTRUCTIVE = ['site:remove', 'site:db-drop', 'owner:remove', 'ssl:remove'];

    public static function destroys(string $command): bool
    {
        return in_array($command, self::DESTRUCTIVE, true);
    }

    /**
     * What a site's removal takes with it, by the ticks the plan carries (audit msg-15f90f9f): `files_db`, `files`, `db`,
     * or null for the site alone and for any other command. The question and the button say it in words.
     *
     * @param array<string, string> $args
     */
    public static function removesWith(string $command, array $args): ?string
    {
        if ($command !== 'site:remove') {
            return null;
        }
        $files = ($args['remove-files'] ?? 'no') === 'yes';
        $db = ($args['drop-db'] ?? 'no') === 'yes';

        return $files && $db ? 'files_db' : ($files ? 'files' : ($db ? 'db' : null));
    }

    /**
     * The question a removal asks once more, its target named and, for a site, what goes with it.
     *
     * @param array<string, string> $args
     */
    public static function question(string $command, array $args, string $target): string
    {
        $with = self::removesWith($command, $args);

        return $with === null
            ? self::text('plan.ask.'.str_replace(':', '-', $command), ['target' => $target]) ?? $command
            : self::text('plan.ask_remove_with', ['target' => $target, 'with' => self::text('plan.with.'.$with) ?? $with]) ?? $command;
    }

    /**
     * The words of the plan's button: «Выполнить», or for a removal «Удалить», with what goes with a site.
     *
     * @param array<string, string> $args
     */
    public static function confirmation(string $command, array $args): string
    {
        $with = self::removesWith($command, $args);
        $key = !self::destroys($command) ? 'plan.confirm' : ($with === null ? 'plan.confirm_remove' : 'plan.confirm_remove_with');

        return self::text($key, $with === null ? [] : ['with' => self::text('plan.with.'.$with) ?? $with]) ?? $key;
    }

    /** The arguments the plan's own dictionary names (`plan.args.*`), the route's later fields among them. */
    public const array ARGS = ['domain', 'profile', 'route', 'owner', 'create-db', 'db-engine', 'admin-user', 'admin-email', 'locale', 'git', 'ref',
        'title', 'source', 'url', 'mode', 'daily-backup', 'bitrix-files', 'redirect-to', 'redirect-code', 'redirect-path', 'upstream-port',
        'upstream-path', 'websockets', 'check-dns', 'migrate', 'id', 'path', 'to', 'port', 'proto', 'action', 'address', 'allow', 'countries',
        'from', 'kind', 'target', 'token', 'provider', 'set', 'schedule', 'command', 'content', 'ttl', 'security-only', 'enable', 'check', 'dump',
        'archive', 'by', 'change_id', 'expect-sha256', 'remove', 'ip-access', 'code', 'without-secrets'];

    /** The values said in words, by argument: the dictionary they are in and the values it has. */
    private const array VALUES = [
        'profile' => ['new_site.profiles.', ['static', 'generic', 'laravel', 'wordpress', 'node', 'redirect', 'bitrix', 'proxy']],
        'db-engine' => ['new_site.engines.', ['mysql', 'pgsql']],
        'bitrix-files' => ['new_site.bitrix_files_choices.', ['none', 'setup', 'restore']],
        'route' => ['routes.names.', ['new', 'restore', 'repo', 'installed', 'none']],
        'redirect-code' => ['routes.values.', ['301', '302', '307', '308']],
        'code' => ['routes.values.', ['301', '302', '307', '308']],
        'redirect-path' => ['routes.values.', ['keep', 'drop']],
        'locale' => ['plan.values.locale.', ['ru_RU', 'en_US', 'en_GB', 'de_DE', 'fr_FR', 'es_ES', 'it_IT', 'uk', 'kk', 'be']],
        'challenge' => ['plan.values.challenge.', ['http', 'dns']],
        'from' => ['plan.values.from.', ['any']],
        'kind' => ['plan.values.kind.', ['webhook', 'telegram', 'off']],
        'mode' => ['plan.values.mode.', ['serve', 'redirect', 'deny', 'allow']],
        'action' => ['plan.values.action.', ['allow', 'deny']],
        'proto' => ['plan.values.proto.', ['tcp', 'udp']],
        'provider' => ['plan.values.provider.', ['cloudflare']],
        'set' => ['plan.values.set.', ['basic', 'enhanced', 'strict']],
    ];

    /** What a plan does and holds, in words where the server says one of these. */
    private const array EFFECTS = ['write', 'delete', 'read'];
    private const array LOCKS = ['host', 'site', 'none'];

    private function __construct()
    {
    }

    /**
     * @param array<string, string> $args
     * @param list<string> $without the values the plan says in its own words above (a check's id, said by its title)
     * @return list<array{name: string, label: string, value: string}>
     */
    public static function of(array $args, ?string $target, array $without = []): array
    {
        $rows = [];
        foreach ($args as $name => $value) {
            // The thing acted on is said once, as «Над чем»: a site's domain or a service's or a base's name.
            if ((in_array($name, ['domain', 'name'], true) && $value === $target) || in_array($name, $without, true)) {
                continue;
            }
            $rows[] = ['name' => $name, 'label' => self::label($name), 'value' => self::value($name, $value)];
        }

        return $rows;
    }

    /** The argument's name as the form says it, else as the plan's dictionary does; else the server's. */
    public static function label(string $name): string
    {
        return (in_array($name, self::NAMED, true) ? self::text('fields.'.$name) : null)
            ?? (in_array($name, self::ARGS, true) ? self::text('plan.args.'.$name) : null)
            ?? $name;
    }

    /** The value in words: yes and no, then the dictionary of the argument's values; else the server's own. */
    public static function value(string $name, string $value): string
    {
        if ($value === 'yes' || $value === 'no') {
            return self::text($value) ?? $value;
        }
        [$prefix, $known] = self::VALUES[$name] ?? ['', []];

        return in_array($value, $known, true) ? self::text($prefix.$value) ?? $value : $value;
    }

    /** What the plan does to the server, in words (write, delete); an unknown effect keeps the server's word. */
    public static function effects(string $effects): string
    {
        return in_array($effects, self::EFFECTS, true) ? self::text('plan.effects_values.'.$effects) ?? $effects : $effects;
    }

    /** What the plan holds while it runs, in words (the whole server, the site); none said as a dash. */
    public static function lock(?string $lock): string
    {
        return $lock === null ? '—' : (in_array($lock, self::LOCKS, true) ? self::text('plan.locks.'.$lock) ?? $lock : $lock);
    }

    /** @param array<string, string> $replace */
    private static function text(string $key, array $replace = []): ?string
    {
        if (!function_exists('__')) {
            return null;
        }
        $line = (string) __('larena-env::admin.'.$key, $replace);

        return $line !== 'larena-env::admin.'.$key ? $line : null;
    }
}
