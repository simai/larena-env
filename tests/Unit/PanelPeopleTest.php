<?php

declare(strict_types=1);

// «Люди» (roles study part 7, PX-67…PX-70): one list of the panel's people, what each may do in words — «Весь сервер»,
// «Свои сайты: 3» — and the last sign-in by its own time; «Посмотреть как он» only for another person who has an
// account and works; «⋯» blocks or unblocks; the signed-in person reads «вы», never their own address. The lines the
// screens ask for exist in both languages.

use Larena\Env\Admin\ServerPeoplePage;
use Larena\Env\People\PanelPeople;

require_once dirname(__DIR__).'/Support/env-test.php';

$lines = require dirname(__DIR__, 2).'/resources/lang/en/admin.php';
$russian = require dirname(__DIR__, 2).'/resources/lang/ru/admin.php';

/** @param array<string, mixed> $lines @param array<string, string> $replace */
function people_line(array $lines, string $key, array $replace = []): string
{
    $value = $lines;
    foreach (explode('.', $key) as $part) {
        assert(is_array($value) && array_key_exists($part, $value), 'line '.$key);
        $value = $value[$part];
    }
    assert(is_string($value), 'line '.$key.' is text');
    foreach ($replace as $name => $with) {
        $value = str_replace(':'.$name, $with, $value);
    }

    return $value;
}

$text = static fn (string $key, array $replace = []): string => people_line($russian, $key, $replace);
$person = static fn (array $over): array => array_merge(['id' => 1, 'subject' => 'user:admin_identity:1', 'name' => 'Rim',
    'email' => 'admin@example.test', 'level' => PanelPeople::ALL, 'blocked' => false, 'owner' => null, 'sites' => [], 'last' => null,
    'secret' => true, 'pending' => false], $over);
$now = new DateTimeImmutable('2026-11-18T12:00:00Z');

$people = [
    $person([]),
    $person(['id' => 2, 'subject' => 'user:admin_identity:2', 'name' => 'Staff', 'email' => 'staff@example.test', 'level' => PanelPeople::OWN,
        'owner' => 'demo', 'sites' => ['a.test', 'b.test', 'c.test'], 'last' => '2026-11-18T11:30:00Z', 'secret' => false]),
    $person(['id' => 3, 'subject' => 'user:admin_identity:3', 'name' => 'Blocked', 'email' => 'blocked@example.test', 'level' => PanelPeople::OWN,
        'owner' => 'old', 'blocked' => true, 'secret' => false]),
    $person(['id' => 4, 'subject' => 'user:admin_identity:4', 'name' => 'New', 'email' => 'new@example.test', 'level' => PanelPeople::OWN, 'secret' => false]),
];

// What a person may do, in words.
assert(ServerPeoplePage::can($people[0], $text) === 'Весь сервер');
assert(ServerPeoplePage::can($people[1], $text) === people_line($russian, 'people.can.own', ['count' => '3']));
assert(str_contains(ServerPeoplePage::can($people[1], $text), '3'));
assert(ServerPeoplePage::can($people[3], $text) === people_line($russian, 'people.can.no_account'));
assert(PanelPeople::roleOf(PanelPeople::ALL) !== PanelPeople::roleOf(PanelPeople::OWN), 'the two levels are two roles');

$page = ServerPeoplePage::of($people, 'user:admin_identity:1', $text, true, $now);
$rows = [];
foreach ($page->rows as $row) {
    $rows[$row['id']] = $row;
}
assert(array_keys($rows) === [1, 2, 3, 4] && $rows[1]['id'] === '1');
assert(!in_array('groups', array_column($page->columns, 'key'), true), 'no «Группы» column: the panel has its two levels');
// The signed-in person is «вы»; no acting as themselves and no blocking themselves.
assert($rows['1']['email'] === people_line($russian, 'people.you') && !str_contains(json_encode($rows['1'], JSON_THROW_ON_ERROR), 'admin@example.test'));
assert($rows['1']['_actions'] === ['view']);
// Another person of their own sites: «Посмотреть как он» and blocking; the last sign-in by its own time.
assert($rows['2']['_actions'] === ['view', 'edit', 'block']);
assert($rows['2']['last']['tip'] !== '' && $rows['2']['last']['label'] !== people_line($russian, 'people.never'));
// A blocked person: no acting as them; unblocking instead; the dot says so.
assert($rows['3']['_actions'] === ['view', 'unblock'] && $rows['3']['state']['value'] === 'stopped');
// Never signed in.
assert($rows['4']['last']['label'] === people_line($russian, 'people.never'));
// «Пригласить человека» is the one filled button; a reader has none, and no row actions.
assert(count($page->headerActions) === 1 && $page->headerActions[0]['primary'] === true);
$reader = ServerPeoplePage::of($people, 'user:admin_identity:1', $text, false, $now);
// Invited by mail and not answered (C335): «Приглашён» and until when the link works, or that it ran out.
$invited = ServerPeoplePage::of([$people[0], $person(['id' => 5, 'subject' => 'user:admin_identity:5', 'name' => 'Invited', 'email' => 'inv@example.test',
    'level' => PanelPeople::ALL, 'secret' => false, 'pending' => true, 'until' => '2026-11-20 12:00:00']),
    $person(['id' => 6, 'subject' => 'user:admin_identity:6', 'name' => 'Late', 'email' => 'late@example.test', 'level' => PanelPeople::ALL,
        'secret' => false, 'pending' => true, 'until' => '2026-11-17 12:00:00'])], 'user:admin_identity:1', $text, true, $now);
$invitedRows = array_column($invited->rows, null, 'id');
assert($invitedRows['5']['state']['value'] === 'invited' && $invitedRows['5']['state']['label'] === people_line($russian, 'people.state.invited'));
assert(str_contains(json_encode($invitedRows['5'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'Ждёт ответа до'), 'the invitation waits until a time');
assert(str_contains(json_encode($invitedRows['6'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), people_line($russian, 'people.invite.pending_expired')), 'an invitation that ran out says so');
assert($reader->headerActions === [] && $reader->quickActions === [] && $reader->rowWrites === []);

// The lines both languages carry for the list, the invitation and the card.
foreach ([$lines, $russian] as $language) {
    foreach (['people.navigation', 'people.act_as', 'people.invite.open', 'people.invite.heading', 'people.invite.show_plan', 'people.invite.account_new',
        'people.invite.levels.own.label', 'people.invite.levels.all.label', 'people.card.summary', 'people.card.account', 'people.block.open',
        'people.block.unopen', 'people.secret_files', 'people.can.all', 'people.can_said.own', 'people.can_said.all', 'access.row.sftp-remove'] as $key) {
        people_line($language, $key);
    }
}
// PX-68: one name for the server's account; the old ones are gone from the Russian screens.
$flat = json_encode($russian['people'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
foreach (['рабочее место', 'Рабочее место', 'Чей сайт', 'Site owners'] as $old) {
    assert(!str_contains($flat, $old), 'no «'.$old.'» on the people screens');
}

echo "PanelPeopleTest passed.\n";
