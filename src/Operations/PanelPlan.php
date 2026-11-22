<?php

declare(strict_types=1);

namespace Larena\Env\Operations;

use Larena\Env\Software\SystemUpdates;
use Larena\Env\Update\UpdatePlan;
use UnexpectedValueException;

/** What an action would do, as the gateway answers it before anything runs (`kind: plan`). */
final readonly class PanelPlan
{
    /**
     * @param array<string, string> $args
     * @param ?UpdatePlan $update what a safe update would change (self:update's plan carries it, simai-env 1.32.0)
     * @param ?SystemUpdates $system the packages a system update would install (software:update's plan, simai-env 1.38.0)
     * @param ?PlanCheck $check the rule of a site's check a fix would put right (site:check-fix's plan, simai-env 1.55.1)
     */
    public function __construct(
        public string $command,
        public array $args,
        public ?string $target,
        public string $description,
        public string $effects,
        public ?string $lock,
        public ?UpdatePlan $update = null,
        public ?SystemUpdates $system = null,
        public ?PlanCheck $check = null,
        // What a new site or a restored copy asks of the server (site:add, bitrix:restore-ready, simai-env 1.56.0).
        public ?PlanResources $resources = null,
        // The plan in words (simai-env 1.60.0, `lang`): its title, each value with its label and words, what it does to
        // data, the sentence for what deletes or replaces data, and what the plan's check found.
        public ?string $title = null,
        /** @var list<array{key: string, label: string, value: string, words: string}> */
        public array $display = [],
        public ?string $effectsWords = null,
        public ?string $consequence = null,
        public ?PlanChecks $checks = null,
        // service:stop (simai-env 1.62.0): the sites that lose something while the service is stopped, and what each loses
        // (scheduler, cache, sessions, queue, workers).
        /** @var list<array{domain: string, uses: list<string>}> */
        public array $affected = [],
        // site:cache-clear (simai-env 1.57.0): each cache, whether it will be cleared or is skipped, and why.
        /** @var list<array{name: string, state: string, reason: ?string}> */
        public array $clears = [],
        // service:stop: whether the server's own checks and alerts pause with it (cron).
        public bool $pausesServer = false,
        // owner:create with sites (simai-env 1.63.1): the account, whether it is new, and each site with the account it
        // leaves (null: the site belonged to no account).
        /** @var array{owner: string, new: bool, sites: list<array{domain: string, from: ?string}>}|null */
        public ?array $account = null,
        // site:transfer (simai-env 1.57.0): the account the site leaves (null: none), the one it goes to, whether a backup
        // comes first, whether the site keeps serving and whether it has a database, which stays as it is.
        /** @var array{domain: string, from: ?string, to: string, backup: bool, serving: bool, database: bool}|null */
        public ?array $transfer = null,
        // site:restore-ready (simai-env 1.63.4): what the check found — the site's folders, the archive and the dump, the
        // database and its tables, files already there, whether a backup is taken (\Larena\Env\Admin\RestoreWords::found).
        /** @var array<string, mixed>|null */
        public ?array $restore = null,
        // file:trash-empty (simai-env 1.64.0): how many entries and bytes go for good, all of them or the one asked.
        /** @var array{items: int, bytes: int}|null */
        public ?array $purge = null,
        // site:preview-link (simai-env 1.65.0): the address without the key, the minutes, and whether it renews an open link.
        /** @var array{address: ?string, minutes: ?int, renew: bool}|null */
        public ?array $preview = null,
    ) {
    }

    /**
     * A value's words as the server says them, by its argument; null when the server said none.
     *
     * @return array{key: string, label: string, value: string, words: string}|null
     */
    public function said(string $key): ?array
    {
        foreach ($this->display as $row) {
            if ($row['key'] === $key) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @throws UnexpectedValueException
     */
    public static function fromData(array $data): self
    {
        $command = $data['command'] ?? null;
        $description = $data['description'] ?? null;
        $effects = $data['effects'] ?? null;
        if (!is_string($command) || !is_string($description) || !is_string($effects)) {
            throw new UnexpectedValueException('plan_field_invalid');
        }
        // A plan that says it has already been applied is not a plan: the panel never shows it as one.
        if (($data['applied'] ?? false) !== false) {
            throw new UnexpectedValueException('plan_already_applied');
        }
        $args = [];
        foreach (is_array($data['args'] ?? null) ? $data['args'] : [] as $name => $value) {
            // The addresses that reach the panel are the one list an argument may be; the plan shows them one after another.
            if (is_array($value) && array_is_list($value) && array_filter($value, 'is_string') === $value) {
                $value = implode(', ', $value);
            }
            if (!is_string($value)) {
                throw new UnexpectedValueException('plan_field_invalid');
            }
            $args[(string) $name] = $value;
        }

        return new self(
            command: $command,
            args: $args,
            target: is_string($data['target'] ?? null) ? $data['target'] : null,
            description: $description,
            effects: $effects,
            lock: is_string($data['lock'] ?? null) ? $data['lock'] : null,
            update: is_array($data['update'] ?? null) ? UpdatePlan::fromData($data['update']) : null,
            system: $command === 'software:update' && is_array($data['update'] ?? null) ? SystemUpdates::fromData($data['update']) : null,
            check: $command === 'site:check-fix' && is_array($data['check'] ?? null) ? PlanCheck::fromData($data['check']) : null,
            resources: is_array($data['resources'] ?? null) ? PlanResources::fromData($data['resources'], is_array($data['archive'] ?? null) ? $data['archive'] : null) : null,
            title: self::words($data['title'] ?? null),
            display: self::display($data['display'] ?? null),
            effectsWords: self::words($data['effects_words'] ?? null),
            consequence: self::words($data['consequence'] ?? null),
            // A stop's notes repeat in the server's English what the panel says itself from `service` (the sites, the server's
            // own checks); its blockers stay.
            checks: $command === 'service:stop' && is_array($data['service'] ?? null)
                ? new PlanChecks(PlanChecks::fromData($data['checks'] ?? null)->blockers ?? [], []) : PlanChecks::fromData($data['checks'] ?? null),
            pausesServer: $command === 'service:stop' && is_array($data['service'] ?? null) && ($data['service']['server'] ?? false) === true,
            affected: $command === 'service:stop' ? self::affected($data['service'] ?? null) : [],
            clears: $command === 'site:cache-clear' ? self::clears($data['clear'] ?? null) : [],
            account: $command === 'owner:create' ? self::account($data['owner'] ?? null) : null,
            transfer: $command === 'site:transfer' ? self::transfer($data['transfer'] ?? null) : null,
            restore: $command === 'site:restore-ready' ? \Larena\Env\Admin\RestoreWords::found($data['restore'] ?? null) : null,
            purge: $command === 'file:trash-empty' && is_array($data['trash'] ?? null) && is_int($data['trash']['items'] ?? null) && is_int($data['trash']['bytes'] ?? null)
                ? ['items' => max(0, $data['trash']['items']), 'bytes' => max(0, $data['trash']['bytes'])] : null,
            preview: $command === 'site:preview-link' && is_array($data['preview'] ?? null) ? [
                'address' => is_string($data['preview']['address'] ?? null) && \Larena\Env\Server\PreviewLinkState::isAddress($data['preview']['address']) ? $data['preview']['address'] : null,
                'minutes' => is_int($data['preview']['minutes'] ?? null) ? $data['preview']['minutes'] : null,
                'renew' => ($data['preview']['renew'] ?? false) === true,
            ] : null,
        );
    }

    /**
     * The account the server would make and the sites that move to it, each with the account it leaves: the server says
     * `owner demo` for one, `its own user …` or `the shared user …` for a site of no account.
     *
     * @return array{owner: string, new: bool, sites: list<array{domain: string, from: ?string}>}|null
     */
    private static function account(mixed $owner): ?array
    {
        if (!is_array($owner) || !is_string($owner['owner'] ?? null) || $owner['owner'] === '') {
            return null;
        }
        $sites = [];
        foreach (is_array($owner['sites'] ?? null) ? $owner['sites'] : [] as $site) {
            if (is_array($site) && is_string($site['domain'] ?? null) && $site['domain'] !== '') {
                $sites[] = ['domain' => $site['domain'], 'from' => self::fromAccount($site['from'] ?? null)];
            }
        }

        return ['owner' => $owner['owner'], 'new' => ($owner['new'] ?? true) !== false, 'sites' => $sites];
    }

    /** The account a site leaves, from the server's words: `owner demo` names one; anything else is no account. */
    public static function fromAccount(mixed $from): ?string
    {
        return is_string($from) && preg_match('/\Aowner ([a-z][a-z0-9_-]{0,31})\z/D', trim($from), $found) === 1 ? $found[1] : null;
    }

    /** @return array{domain: string, from: ?string, to: string, backup: bool, serving: bool, database: bool}|null */
    private static function transfer(mixed $transfer): ?array
    {
        if (!is_array($transfer) || !is_string($transfer['domain'] ?? null) || !is_string($transfer['to_owner'] ?? null) || $transfer['to_owner'] === '') {
            return null;
        }
        $moves = array_values(array_filter(is_array($transfer['moves'] ?? null) ? $transfer['moves'] : [], 'is_string'));
        $downtime = is_string($transfer['downtime'] ?? null) ? $transfer['downtime'] : '';

        return [
            'domain' => $transfer['domain'],
            'from' => is_string($transfer['from_owner'] ?? null) && $transfer['from_owner'] !== '' ? $transfer['from_owner'] : null,
            'to' => $transfer['to_owner'],
            'backup' => is_string($transfer['backup'] ?? null) && $transfer['backup'] !== '',
            // simai-env says «none: …» where the site keeps serving; an older answer without the field is taken as the same.
            'serving' => $downtime === '' || str_starts_with($downtime, 'none'),
            'database' => array_filter($moves, static fn (string $line): bool => str_starts_with($line, 'database ')) !== [],
        ];
    }

    /** @return list<array{domain: string, uses: list<string>}> */
    private static function affected(mixed $service): array
    {
        $sites = [];
        foreach (is_array($service) && is_array($service['sites'] ?? null) ? $service['sites'] : [] as $site) {
            if (is_array($site) && is_string($site['domain'] ?? null) && $site['domain'] !== '') {
                $sites[] = ['domain' => $site['domain'], 'uses' => array_values(array_filter(is_array($site['uses'] ?? null) ? $site['uses'] : [],
                    static fn (mixed $use): bool => is_string($use) && preg_match('/\A[a-z_]{1,32}\z/D', $use) === 1))];
            }
        }

        return $sites;
    }

    /** @return list<array{name: string, state: string, reason: ?string}> */
    private static function clears(mixed $items): array
    {
        $clears = [];
        foreach (is_array($items) ? $items : [] as $item) {
            if (is_array($item) && is_string($item['name'] ?? null) && preg_match('/\A[a-z_]{1,32}\z/D', $item['name']) === 1) {
                $clears[] = ['name' => $item['name'], 'state' => ($item['state'] ?? null) === 'planned' ? 'planned' : 'skipped',
                    'reason' => is_string($item['reason'] ?? null) && $item['reason'] !== '' ? $item['reason'] : null];
            }
        }

        return $clears;
    }

    private static function words(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, 500) : null;
    }

    /** @return list<array{key: string, label: string, value: string, words: string}> */
    private static function display(mixed $rows): array
    {
        $kept = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row) || !is_string($row['key'] ?? null)) {
                continue;
            }
            $value = $row['value'] ?? '';
            $value = is_array($value) ? implode(', ', array_filter($value, 'is_string')) : (is_scalar($value) ? (string) $value : '');
            $kept[] = ['key' => $row['key'], 'label' => self::words($row['label'] ?? null) ?? $row['key'], 'value' => $value,
                'words' => self::words($row['words'] ?? null) ?? $value];
        }

        return $kept;
    }
}
