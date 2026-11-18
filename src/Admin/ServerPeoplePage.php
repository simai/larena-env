<?php

declare(strict_types=1);

namespace Larena\Env\Admin;

use Larena\Admin\Runtime\AdminDataviewPage;
use Larena\Env\People\PanelPeople;
use Larena\Env\Server\Moment;

/**
 * «Люди» as the admin's shared list of resources (roles study part 7, PX-67…PX-70; lists C31): a dot says whether the
 * person works or is blocked, the name over the email (the signed-in person reads «вы», never their address), what they
 * may do in words — «Весь сервер», «Свои сайты: 3» — and the last sign-in by its own time. No «Группы»: the panel has its
 * two levels only. In the row «Посмотреть как он» for a person of their own sites, and «⋯» with the card and blocking.
 * «Пригласить человека» is the list's one filled button; it opens the invitation form in the side panel.
 */
final class ServerPeoplePage
{
    private function __construct()
    {
    }

    /**
     * @param list<array{id: int, subject: string, name: string, email: string, level: string, blocked: bool, owner: ?string, sites: list<string>, last: ?string, secret: bool, pending: bool, until?: ?string}> $people PanelPeople::all
     * @param callable(string, array<string, string>=): string $text the panel's line for a key under larena-env::admin.
     */
    public static function of(array $people, string $actor, callable $text, bool $canOperate, ?\DateTimeImmutable $now = null): AdminDataviewPage
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $rows = array_map(static fn (array $person): array => self::row($person, $actor, $text, $canOperate, $now), $people);

        return new AdminDataviewPage(
            instanceId: 'env-people',
            title: $text('people.list'),
            columns: [
                ['key' => 'state', 'label' => $text('people.columns.state'), 'list' => 'status', 'type' => 'choice',
                    'options' => [['value' => 'ok', 'label' => $text('people.state.ok'), 'status' => 'ok'],
                        ['value' => 'stopped', 'label' => $text('people.state.blocked'), 'status' => 'stopped'],
                        ['value' => 'invited', 'label' => $text('people.state.invited'), 'status' => 'warning']]],
                ['key' => 'name', 'label' => $text('people.columns.name'), 'role' => 'title', 'list' => 'title'],
                ['key' => 'email', 'label' => $text('people.columns.email'), 'list' => 'supporting'],
                ['key' => 'can', 'label' => $text('people.columns.can'), 'list' => 'fact'],
                ['key' => 'last', 'label' => $text('people.columns.last'), 'list' => 'fact'],
            ],
            total: count($rows),
            pageId: 'page.admin.env-people',
            rows: $rows,
            perPage: 100,
            rowLinks: ['view' => '/admin/server/people/__ID__'],
            settings: true,
            countLabel: $text('people.count'),
            tableViewLabel: $text('people.table_view'),
            // The one filled button of the list (C301): the invitation, in the side panel over the list.
            headerActions: $canOperate ? [['text' => $text('people.invite.open'), 'primary' => true,
                'attributes' => ['data-sf-modal-open' => 'env-invite', 'data-env-invite-open' => 'yes']]] : [],
            // «Посмотреть как он»: the panel asked as the person, marked as acted for (a plain post with the form's token).
            rowWrites: $canOperate ? ['edit' => ['url' => '/admin/server/people/act', 'fields' => ['subject' => 'user:admin_identity:__ID__'],
                'label' => $text('people.act_as')]] : [],
            layout: 'list',
            quickActions: $canOperate ? ['edit'] : [],
            rowActions: $canOperate ? ['block' => ['label' => $text('people.block.open'), 'icon' => 'block', 'href' => '/admin/server/people/__ID__/block'],
                'unblock' => ['label' => $text('people.block.unopen'), 'icon' => 'lock_open', 'href' => '/admin/server/people/__ID__/block']] : [],
            emptyText: $text('people.empty'),
            emptyDescription: $text('people.empty_description'),
        );
    }

    /** What a person may do, in words: «Весь сервер», «Свои сайты: 3». @param array{level: string, owner: ?string, sites: list<string>} $person */
    public static function can(array $person, callable $text): string
    {
        return match ($person['level']) {
            PanelPeople::ALL => $text('people.can.all'),
            PanelPeople::OWN => $person['owner'] === null ? $text('people.can.no_account')
                : ($person['sites'] === [] ? $text('people.can.own_none') : $text('people.can.own', ['count' => (string) count($person['sites'])])),
            default => $text('people.can.read'),
        };
    }

    /** The last sign-in by its own time, the exact one in the tooltip; «ещё не входил» without one. */
    public static function last(?string $last, callable $text, \DateTimeImmutable $now): array
    {
        if ($last === null) {
            return ['label' => $text('people.never'), 'text' => $text('people.never')];
        }
        $ago = Moment::ago($last, $now, $text) ?? Moment::human($last);

        return ['label' => $ago, 'text' => $ago, 'tip' => Moment::human($last)];
    }

    /**
     * @param array{id: int, subject: string, name: string, email: string, level: string, blocked: bool, owner: ?string, sites: list<string>, last: ?string, secret: bool, pending: bool, until?: ?string} $person
     * @return array<string, mixed>
     */
    private static function row(array $person, string $actor, callable $text, bool $canOperate, \DateTimeImmutable $now): array
    {
        $self = $person['subject'] === $actor;
        $can = self::can($person, $text);
        $actions = ['view'];
        if ($canOperate && !$self && $person['owner'] !== null && !$person['blocked']) {
            $actions[] = 'edit';
        }
        if ($canOperate && !$self) {
            $actions[] = $person['blocked'] ? 'unblock' : 'block';
        }

        // Invited by mail and not answered yet (C335): «Приглашён», and until when the link works where the panel sent it.
        if ($person['pending'] && !$person['blocked']) {
            $until = is_string($person['until'] ?? null) ? $person['until'] : null;
            $left = $until === null ? null : ((strtotime($until.' UTC') ?: 0) > $now->getTimestamp()
                ? $text('people.invite.pending', ['time' => Moment::human($until)]) : $text('people.invite.pending_expired'));

            return [
                'id' => (string) $person['id'],
                'state' => ['value' => 'invited', 'label' => $text('people.state.invited')],
                'name' => $person['name'],
                'email' => $person['email'],
                'can' => ['label' => $text('people.state.invited'), 'text' => $left ?? $text('people.state.invited'), 'status' => 'warning'],
                'last' => ['label' => $left ?? $text('people.never'), 'text' => $left ?? $text('people.never')],
                '_actions' => ['view'],
            ];
        }

        return [
            'id' => (string) $person['id'],
            'state' => $person['blocked'] ? ['value' => 'stopped', 'label' => $text('people.state.blocked')] : ['value' => 'ok', 'label' => $text('people.state.ok')],
            'name' => $person['name'],
            // The signed-in person is «вы»: their own address is never printed on the screen others may see over a shoulder.
            'email' => $self ? $text('people.you') : $person['email'],
            'can' => $person['blocked'] ? ['label' => $can, 'text' => $can.' · '.$text('people.state.blocked'), 'status' => 'warning'] : ['label' => $can, 'text' => $can],
            'last' => self::last($person['last'], $text, $now),
            '_actions' => $actions,
        ];
    }
}
