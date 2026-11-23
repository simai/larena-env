<?php

declare(strict_types=1);

// Who hears about the server (auditor msg-3d61bd36, point 1): one list for the bell and the mail, by the rights each person
// holds now. A blocked person and a person whose role was taken away hear nothing; a removed person's link, and the link of
// one who lost the right, is dropped and written to the audit log; a valid owner still hears about their site.

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Larena\Audit\Contracts\AuditSink;
use Larena\Audit\Runtime\InMemoryAuditSink;
use Larena\Env\People\PanelAudit;
use Larena\Env\People\PanelRecipients;
use Larena\Env\People\PersonOwners;

require_once dirname(__DIR__).'/Support/env-test.php';

$capsule = new Capsule();
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$connection = $capsule->getConnection();
$connection->getSchemaBuilder()->create(PersonOwners::TABLE, static function ($table): void {
    $table->string('subject_ref', 191)->primary();
    $table->string('owner', 32);
    $table->string('assigned_by', 191);
    $table->timestamp('assigned_at');
});
$owners = new PersonOwners($connection);
$host = new Container();
$sink = new InMemoryAuditSink();
$host->instance(AuditSink::class, $sink);
$audit = new PanelAudit($host);

$admin = 'user:admin_identity:1';
$blockedAdmin = 'user:admin_identity:2';
$anna = 'user:admin_identity:10';
$blocked = 'user:admin_identity:11';
$revoked = 'user:admin_identity:12';
$removed = 'user:admin_identity:13';
$other = 'user:admin_identity:14';
foreach ([$anna => 'demo', $blocked => 'demo', $revoked => 'demo', $removed => 'demo', $other => 'other'] as $subject => $owner) {
    $owners->assign($subject, $owner, 'test:system');
}
// What Access and Auth say now: the administrators, the rights each person holds, and who is active.
$rights = [
    $admin => ['env.server.read', 'env.sites.read'],
    $blockedAdmin => ['env.server.read', 'env.sites.read'],
    $anna => ['env.sites.read'],
    $blocked => ['env.sites.read'],
    // The editor role was taken away: no env.sites.read any more.
    $revoked => [],
    $removed => ['env.sites.read'],
    $other => ['env.sites.read'],
];
$states = [$blockedAdmin => PanelRecipients::BLOCKED, $blocked => PanelRecipients::BLOCKED, $removed => PanelRecipients::GONE];
$recipients = static function () use ($admin, $blockedAdmin, &$rights, &$states, $owners, $audit): PanelRecipients {
    return new PanelRecipients(
        static fn (): array => [$admin, $blockedAdmin],
        static function (string $subject, string $right) use (&$rights): bool {
            return in_array($right, $rights[$subject] ?? [], true);
        },
        static fn (string $subject): string => $states[$subject] ?? PanelRecipients::ACTIVE,
        $owners,
        $audit,
    );
};

// A site of demo's server account: the active administrator and the valid owner, nobody else.
$told = $recipients()->of('demo');
env_assert($told === [$admin, $anna], 'Only the active administrator and the valid owner are told: '.json_encode($told));
env_assert(!in_array($blocked, $told, true), 'A blocked person gets nothing.');
env_assert(!in_array($revoked, $told, true), 'A person whose role was taken away gets nothing.');
env_assert(!in_array($blockedAdmin, $told, true), 'A blocked administrator gets nothing either.');
env_assert(!in_array($other, $told, true), 'Another server account\'s person hears nothing of it.');
// The server's own warnings: the whole server's people only.
env_assert($recipients()->of(null) === [$admin], 'A server warning goes to the active administrators only.');

// The links that no longer hold were dropped, each written to the audit log; the blocked person's stays.
env_assert($owners->ownerOf($revoked) === null && $owners->ownerOf($removed) === null, 'The removed person\'s and the revoked one\'s links are dropped.');
env_assert($owners->ownerOf($blocked) === 'demo', 'A blocked person keeps the link: unblocking gives it back.');
env_assert($owners->ownerOf($anna) === 'demo' && $owners->ownerOf($other) === 'other', 'Valid links stay.');
$events = array_map(static fn ($event): array => [$event->type, $event->actor, $event->subject, $event->payload], $sink->events());
env_assert($events === [
    ['env.person.owner_released', PanelRecipients::ACTOR, $revoked, ['owner' => 'demo', 'reason' => 'right_revoked']],
    ['env.person.owner_released', PanelRecipients::ACTOR, $removed, ['owner' => 'demo', 'reason' => 'removed']],
], 'Each drop is in the audit log: '.json_encode($events));

// The sweep of every run: someone removed later loses the link even when nothing is to be told; nothing twice.
$states[$other] = PanelRecipients::GONE;
env_assert($recipients()->sweep() === 1 && $owners->ownerOf($other) === null, 'The sweep drops the link of a person removed since.');
env_assert($recipients()->sweep() === 0 && count($sink->events()) === 3, 'Nothing is dropped or written twice.');

// Unblocked again: told again, with the same link.
unset($states[$blocked]);
env_assert($recipients()->of('demo') === [$admin, $anna, $blocked], 'Unblocked, the person hears about their site again.');

// A right that cannot be read now: nobody is told on it and nothing is dropped on a guess.
$unsure = new PanelRecipients(static fn (): array => [$admin], static fn (string $subject, string $right): bool => throw new RuntimeException('down'),
    static fn (string $subject): string => PanelRecipients::ACTIVE, $owners, $audit);
env_assert($unsure->of('demo') === [] && $owners->ownerOf($anna) === 'demo' && count($sink->events()) === 3, 'Unreadable rights: nobody told, nothing dropped.');

// Without Access's rights or Auth's people the list is empty: nobody rather than somebody who may not see it.
$empty = PanelRecipients::from(new Container());
env_assert(!$empty->judges() && $empty->of('demo') === [] && $empty->of(null) === [] && $empty->sweep() === 0, 'Nothing to judge by: nobody is told.');

echo "PanelRecipientsTest passed.\n";
