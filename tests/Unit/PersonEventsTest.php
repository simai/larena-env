<?php

declare(strict_types=1);

// The panel hears Auth and Access (signals sent once, after their change commits) and asks Access who holds the whole
// server's right now. A removed person's link to a server account is dropped at once, in the audit log; a role taken away
// re-checks the links; a custom role holding env.server.read is told; an answer Access could not give tells nobody.

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Schema;
use Larena\Access\Compatibility\Audit\AuditAccessSecurityEventSink;
use Larena\Access\Contracts\AccessSubjectDirectory;
use Larena\Access\Events\RoleRevoked;
use Larena\Access\Events\RoleRightsRevoked;
use Larena\Access\Persistence\AdminIdentitySubjectDirectory;
use Larena\Access\Persistence\PersistentAccessStore;
use Larena\Access\Runtime\AccessMutationAuditor;
use Larena\Access\Runtime\AccessOperationRegistry;
use Larena\Access\Runtime\EffectiveAccessReader;
use Larena\Access\Runtime\SystemRolePresetSynchronizer;
use Larena\Access\ValueObjects\AccessOperationDescriptor;
use Larena\Audit\Contracts\AuditSink;
use Larena\Audit\Runtime\AuditEventPipeline;
use Larena\Audit\Runtime\DefaultAuditRedactor;
use Larena\Audit\Runtime\InMemoryAuditSink;
use Larena\Auth\Events\PersonDeleted;
use Larena\Env\People\PanelAudit;
use Larena\Env\People\PanelRecipients;
use Larena\Env\People\PersonOwners;
use Larena\Env\Providers\EnvServiceProvider;

require_once dirname(__DIR__).'/Support/env-test.php';
require_once dirname(__DIR__).'/Support/AdminPresenceHost.php';

$capsule = new Capsule();
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
$capsule->setAsGlobal();
$connection = $capsule->getConnection();
Schema::swap($connection->getSchemaBuilder());
$connection->getSchemaBuilder()->create(PersonOwners::TABLE, static function (Blueprint $table): void {
    $table->string('subject_ref', 191)->primary();
    $table->string('owner', 32);
    $table->string('assigned_by', 191);
    $table->timestamp('assigned_at');
});
$owners = new PersonOwners($connection);
$sink = new InMemoryAuditSink();
$auditHost = new Container();
$auditHost->instance(AuditSink::class, $sink);
$audit = new PanelAudit($auditHost);

// ---- The signals, heard through the provider -----------------------------------------------------------------------

$anna = 'user:admin_identity:10';
$boris = 'user:admin_identity:11';
$vera = 'user:admin_identity:12';
foreach ([$anna => 'demo', $boris => 'demo', $vera => 'other'] as $subject => $owner) {
    $owners->assign($subject, $owner, 'test:system');
}
// What Access and Auth say now; every one is active and holds env.sites.read until the test changes it.
$rights = [$anna => ['env.sites.read'], $boris => ['env.sites.read'], $vera => ['env.sites.read']];
$asked = 0;
$app = new AdminPresenceHost();
$events = new Dispatcher($app);
$app->instance('events', $events);
$app->bind(PanelRecipients::class, static function () use (&$rights, &$asked, $owners, $audit): PanelRecipients {
    return new PanelRecipients(
        static fn (): array => [],
        static function (string $subject, string $right) use (&$rights, &$asked): bool {
            $asked++;

            return in_array($right, $rights[$subject] ?? [], true);
        },
        static fn (string $subject): string => PanelRecipients::ACTIVE,
        $owners,
        $audit,
    );
});
$listen = static fn (EnvServiceProvider $provider, bool $on): mixed => (new ReflectionMethod(EnvServiceProvider::class, 'listenToPeople'))->invoke($provider, $on);

// The panel's people off (neither env.admin nor env.notifications): nothing is heard.
$listen(new EnvServiceProvider($app), false);
env_assert(!$events->hasListeners(PersonDeleted::class) && !$events->hasListeners(RoleRevoked::class) && !$events->hasListeners(RoleRightsRevoked::class),
    'With the panel\'s people off, nothing is heard.');
$listen(new EnvServiceProvider($app), true);

// A person removed: the link goes at once, written as env.person.owner_released with reason removed, nobody else asked.
$events->dispatch(PersonDeleted::of(10));
env_assert($owners->ownerOf($anna) === null && $owners->ownerOf($boris) === 'demo' && $owners->ownerOf($vera) === 'other', 'Only the removed person\'s link is dropped.');
$written = static fn (): array => array_map(static fn ($event): array => [$event->type, $event->actor, $event->subject, $event->payload], $sink->events());
env_assert($written() === [['env.person.owner_released', PanelRecipients::ACTOR, $anna, ['owner' => 'demo', 'reason' => 'removed']]],
    'The drop is in the audit log: '.json_encode($written()));
env_assert($asked === 0, 'A removed person\'s link is dropped without asking anybody.');
$events->dispatch(PersonDeleted::of(10));
env_assert(count($sink->events()) === 1, 'A person without a link: nothing more is written.');

// A role taken away: that person's link is re-checked and dropped once the right is gone; the others are not asked.
$rights[$boris] = [];
$events->dispatch(new RoleRevoked($boris, 'editor', 'unassigned'));
env_assert($owners->ownerOf($boris) === null && $owners->ownerOf($vera) === 'other' && $asked === 1, 'The role taken away re-checks that person only.');
env_assert($written()[1] === ['env.person.owner_released', PanelRecipients::ACTOR, $boris, ['owner' => 'demo', 'reason' => 'right_revoked']],
    'The revoked link is in the audit log.');

// A custom role narrowed: rights the panel does not read change nothing; env.sites.read gone sweeps every link.
$rights[$vera] = [];
$events->dispatch(new RoleRightsRevoked('support', ['content.item.write'], 'role_updated'));
env_assert($owners->ownerOf($vera) === 'other' && $asked === 1, 'A right the panel does not read: no sweep.');
$events->dispatch(new RoleRightsRevoked('support', ['env.sites.read'], 'role_updated'));
env_assert($owners->ownerOf($vera) === null && count($sink->events()) === 3, 'env.sites.read taken from a role sweeps the links.');

// ---- Who of the whole server is told: Access's holders() ---------------------------------------------------------

$access = dirname(__DIR__, 2).'/vendor/larena/access/database/migrations';
foreach (['2026_07_10_000001_create_larena_access_role_tables.php', '2026_07_12_000001_add_custom_role_fields_to_larena_access_roles.php',
    '2026_07_13_000002_expand_larena_access_subject_roles_to_many.php'] as $migration) {
    (require $access.'/'.$migration)->up();
}
$connection->getSchemaBuilder()->create('larena_admin_identities', static function (Blueprint $table): void {
    $table->id();
    $table->string('status', 32);
    $table->timestamp('disabled_at')->nullable();
});
$registry = new AccessOperationRegistry();
EnvServiceProvider::registerAccessOperations($registry);
// Access's own rights, which giving a role asks for.
foreach (['access.role.read' => 'read', 'access.role.assign' => 'assign', 'access.role.explain' => 'explain'] as $code => $grant) {
    $registry->register(new AccessOperationDescriptor($code, 'larena/access', 'larena-access::operations.'.$grant, 'access.role:all', $grant, 'critical'));
}
$store = new PersistentAccessStore($connection);
(new SystemRolePresetSynchronizer($registry, $store))->synchronizeForLifecycle();
$directory = new AdminIdentitySubjectDirectory($connection);
$auditor = new AccessMutationAuditor(new AuditAccessSecurityEventSink(new AuditEventPipeline(new DefaultAuditRedactor(), [new InMemoryAuditSink()])));
$person = static function (int $id, string $status = 'active') use ($connection): string {
    $connection->table('larena_admin_identities')->insert(['id' => $id, 'status' => $status, 'disabled_at' => $status === 'active' ? null : gmdate('Y-m-d H:i:s')]);

    return 'user:admin_identity:'.$id;
};
$administrator = $person(1);
$watcher = $person(2);
$blockedWatcher = $person(3, 'disabled');
$editor = $person(4);
$store->assignRole($administrator, SystemRolePresetSynchronizer::ADMINISTRATOR, 'actor:test');
$store->createCustomRole('server_watch', 'Server watch', null, ['env.server.read'], 'actor:test');
$store->assignRole($watcher, 'server_watch', 'actor:test');
$store->assignRole($blockedWatcher, 'server_watch', 'actor:test');
$store->assignRole($editor, 'editor', 'actor:test');
// A machine subject holding the right is never told: the bell and the mail are for people.
$store->createCustomRole('machine_watch', 'Machine watch', null, ['env.server.read'], 'actor:test');
$store->assignRole('service:backup-agent', 'machine_watch', 'actor:test');

/** @return Container the application as PanelRecipients::from() reads it, with Access's reader built on $operations */
$host = static function (AccessOperationRegistry $operations) use ($store, $registry, $directory, $auditor): Container {
    $host = new Container();
    $host->instance(PersistentAccessStore::class, $store);
    $host->instance(AccessOperationRegistry::class, $registry);
    $host->instance(AccessSubjectDirectory::class, $directory);
    $host->instance(EffectiveAccessReader::class, new EffectiveAccessReader($store, $operations, $directory, $auditor));

    return $host;
};
$told = PanelRecipients::from($host($registry))->of(null);
env_assert($told === [$administrator, $watcher], 'The administrator and the custom role holding env.server.read are told, the blocked one and the machine not: '.json_encode($told));

// Before, only the administrator role's people were told; without holders() bound that stays so.
$before = $host($registry);
$before->forgetInstance(EffectiveAccessReader::class);
env_assert(PanelRecipients::from($before)->of(null) === [$administrator], 'Without Access\'s reader, the administrator role\'s people as before.');

// An answer Access could not give (here: the reader does not know the operation) tells nobody, and nothing escapes.
$told = PanelRecipients::from($host(new AccessOperationRegistry()))->of(null);
env_assert($told === [], 'A non-resolved answer tells nobody: '.json_encode($told));

echo "PersonEventsTest passed.\n";
