<?php

declare(strict_types=1);

namespace Larena\Env\Admin;

use Closure;

/**
 * Sites moving between server accounts, said in the panel's words (roles study, second wave): a new account with the
 * sites it takes in one job (owner:create `sites`, simai-env 1.63.1), and one site handed to another account
 * (site:transfer, simai-env 1.57.0). The server's plan says what moves and from whom; these are its facts for the
 * admin's «Действие с планом», and each refusal the server names, in a sentence a person can act on.
 */
final class AccountMoves
{
    /** What stops a new account with sites; then no account is made and nothing changes. */
    public const array CREATE_REFUSALS = ['site_missing', 'alias', 'panel_site', 'owner_blocked', 'too_many', 'site_unsupported'];

    /** What stops a site's handing over. */
    public const array TRANSFER_REFUSALS = ['owner_missing', 'owner_blocked', 'same_owner', 'panel_site', 'alias', 'site_missing'];

    /** The most sites one job takes (the server refuses more as too_many). */
    public const int MOST_SITES = 50;

    private function __construct()
    {
    }

    /**
     * The facts of a new account's sites: each site and the account it leaves, how a site moves, and what happens when
     * one does not move.
     *
     * @param array{owner: string, new: bool, sites: list<array{domain: string, from: ?string}>} $account
     * @param Closure(string, array<string, string>=): string $text a line of larena-env::admin by its key
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function createFacts(array $account, Closure $text): array
    {
        $facts = [];
        foreach ($account['sites'] as $site) {
            $facts[] = [$site['domain'], $site['from'] === null ? $text('people.invite.fact.site_from_none')
                : $text('people.invite.fact.site_from_account', ['account' => $site['from']])];
        }
        if ($account['sites'] !== []) {
            $facts[] = [$text('people.invite.fact.how'), $text('people.invite.fact.how_value')];
            $facts[] = [$text('people.invite.fact.failure'), $text('people.invite.fact.failure_value')];
        }

        return $facts;
    }

    /**
     * The facts of handing one site over: from whom to whom, the backup first, no break, the previous account's lost
     * access, the database left as it is, and the way back.
     *
     * @param array{domain: string, from: ?string, to: string, backup: bool, serving: bool, database: bool} $transfer
     * @param Closure(string, array<string, string>=): string $text
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function transferFacts(array $transfer, Closure $text): array
    {
        $facts = [
            [$text('transfer.fact.now'), $transfer['from'] === null ? $text('transfer.fact.now_none') : $text('transfer.fact.now_account', ['account' => $transfer['from']])],
            [$text('transfer.fact.to'), $text('transfer.fact.to_value', ['account' => $transfer['to']])],
        ];
        if ($transfer['backup']) {
            $facts[] = [$text('transfer.fact.backup'), $text('transfer.fact.backup_value')];
        }
        $facts[] = [$text('transfer.fact.downtime'), $text('transfer.fact.downtime_'.($transfer['serving'] ? 'none' : 'short'))];
        if ($transfer['from'] !== null) {
            $facts[] = [$text('transfer.fact.previous', ['account' => $transfer['from']]), $text('transfer.fact.previous_value')];
        }
        if ($transfer['database']) {
            $facts[] = [$text('transfer.fact.database'), $text('transfer.fact.database_value', ['account' => $transfer['to']])];
        }
        $facts[] = [$text('transfer.fact.back'), $transfer['from'] === null ? $text('transfer.fact.back_none')
            : $text('transfer.fact.back_account', ['account' => $transfer['from']])];

        return $facts;
    }

    /**
     * A refusal of owner:create or site:transfer in words; null for one the panel has no sentence of its own for.
     *
     * @param Closure(string, array<string, string>=): string $text
     */
    public static function refusal(string $command, string $reason, Closure $text): ?string
    {
        return match (true) {
            $command === 'owner:create' && in_array($reason, self::CREATE_REFUSALS, true) => $text('people.invite.refusals.'.$reason).' '.$text('people.invite.refusals.nothing_made'),
            $command === 'site:transfer' && in_array($reason, self::TRANSFER_REFUSALS, true) => $text('transfer.refusals.'.$reason),
            default => null,
        };
    }

    /**
     * The sites a new account may take, as the invitation offers them: each with the account it is with now. A list the
     * person sent back is kept to the sites the server has, in its order, at most fifty.
     *
     * @param list<\Larena\Env\Server\SiteSummary> $sites the server's sites (the panel's own is already left out)
     *
     * @return list<array{domain: string, owner: ?string}>
     */
    public static function offered(array $sites): array
    {
        $offered = [];
        foreach ($sites as $site) {
            if ($site->profile !== 'alias') {
                $offered[] = ['domain' => $site->domain, 'owner' => $site->owner];
            }
        }

        return $offered;
    }

    /**
     * The chosen domains that are among those offered, in the offered order; null when one is not (a form left from an
     * older page, or a typed value), so the person chooses again rather than a site being dropped unsaid.
     *
     * @param list<array{domain: string, owner: ?string}> $offered
     *
     * @return list<string>|null
     */
    public static function chosen(mixed $asked, array $offered): ?array
    {
        // The list sends its values joined by commas (sf-dropdown multiple), or one entry each.
        $asked = is_string($asked) ? explode(',', $asked) : $asked;
        $asked = is_array($asked) ? array_values(array_unique(array_filter(array_map(static fn (mixed $one): mixed => is_string($one) ? trim($one) : $one, $asked),
            static fn (mixed $one): bool => is_string($one) && $one !== ''))) : [];
        $known = array_column($offered, 'domain');
        if (array_diff($asked, $known) !== []) {
            return null;
        }

        return array_values(array_filter($known, static fn (string $domain): bool => in_array($domain, $asked, true)));
    }
}
