<?php

declare(strict_types=1);

// The two broad rights split by group (auditor msg-3ee29ace, option B): before and after the update, on Access's own
// store. Whoever held env.sites.operate holds every env.sites.* right, whoever held env.server.operate every env.server.*
// right: the administrator and editor presets and custom roles alike. Nobody loses access and nobody gains any: every
// declared operation is allowed after the update exactly when its route's old right was held before.

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Schema;
use Larena\Access\Persistence\PersistentAccessStore;
use Larena\Access\Runtime\AccessOperationRegistry;
use Larena\Access\Runtime\SystemRolePresetSynchronizer;
use Larena\Access\ValueObjects\AccessOperationDescriptor;
use Larena\Env\Operations\EnvOperationCatalog;
use Larena\Env\Providers\EnvDeclarations;
use Larena\Env\Providers\EnvServiceProvider;

require_once dirname(__DIR__).'/Support/env-test.php';

$capsule = new Capsule();
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
$capsule->setAsGlobal();
$connection = $capsule->getConnection();
Schema::swap($connection->getSchemaBuilder());
$container = new Container();
Container::setInstance($container);
$container->instance('events', new Dispatcher($container));
$access = dirname(__DIR__, 2).'/vendor/larena/access/database/migrations';
foreach (['2026_07_10_000001_create_larena_access_role_tables.php', '2026_07_12_000001_add_custom_role_fields_to_larena_access_roles.php',
    '2026_07_13_000002_expand_larena_access_subject_roles_to_many.php'] as $migration) {
    (require $access.'/'.$migration)->up();
}

$accessRights = static function (AccessOperationRegistry $registry): AccessOperationRegistry {
    foreach (['access.role.read' => 'read', 'access.role.assign' => 'assign', 'access.role.explain' => 'explain'] as $code => $grant) {
        $registry->register(new AccessOperationDescriptor($code, 'larena/access', 'larena-access::operations.'.$grant, 'access.role:all', $grant, 'critical'));
    }

    return $registry;
};
// The panel before the split: its six rights, no successions.
$old = $accessRights(new AccessOperationRegistry());
foreach (EnvDeclarations::descriptors() as $descriptor) {
    if (!array_key_exists($descriptor->code, EnvOperationCatalog::SPLIT_FROM)) {
        $old->register($descriptor);
    }
}
$new = new AccessOperationRegistry();
EnvServiceProvider::registerAccessOperations($new);
$new = $accessRights($new);
env_assert(count(array_filter($old->all(), static fn ($o): bool => str_starts_with($o->code, 'env.'))) === 6
    && count(array_filter($new->all(), static fn ($o): bool => str_starts_with($o->code, 'env.'))) === 16, 'Six rights before, sixteen after.');
env_assert($new->grantedWith() === (static function (): array {
    $split = EnvOperationCatalog::SPLIT_FROM;
    ksort($split);

    return $split;
})(), 'env names each split right with the right its holders came from.');

$store = new PersistentAccessStore($connection);
(new SystemRolePresetSynchronizer($old, $store))->synchronizeForLifecycle();
$roles = [
    'sites_only' => ['env.sites.read', 'env.sites.operate'],
    'server_only' => ['env.server.read', 'env.server.operate'],
    'both' => ['env.sites.read', 'env.sites.operate', 'env.server.read', 'env.server.operate', 'env.dns.read', 'env.dns.operate'],
    'readers' => ['env.sites.read', 'env.server.read', 'env.dns.read'],
    'neither' => ['access.role.read'],
];
foreach ($roles as $code => $rights) {
    $store->createCustomRole($code, ucfirst($code), null, $rights, 'actor:test');
}
$before = [];
foreach ([...array_keys($roles), SystemRolePresetSynchronizer::ADMINISTRATOR, SystemRolePresetSynchronizer::EDITOR, SystemRolePresetSynchronizer::READER] as $code) {
    $before[$code] = $store->role($code)['operations'] ?? [];
}

(new SystemRolePresetSynchronizer($new, $store))->synchronizeForLifecycle();
$after = [];
foreach (array_keys($before) as $code) {
    $after[$code] = $store->role($code)['operations'] ?? [];
}
$env = static fn (array $rights): array => array_values(array_filter($rights, static fn (string $r): bool => str_starts_with($r, 'env.')));
$sites = ['env.sites.backup', 'env.sites.database', 'env.sites.deploy', 'env.sites.files', 'env.sites.operate', 'env.sites.read', 'env.sites.remove'];
$server = ['env.server.access', 'env.server.backup_storage', 'env.server.operate', 'env.server.read', 'env.server.security', 'env.server.software', 'env.server.update'];
env_assert($env($after['sites_only']) === $sites, 'env.sites.operate held: every env.sites.* right. '.json_encode($after['sites_only']));
env_assert($env($after['server_only']) === $server, 'env.server.operate held: every env.server.* right. '.json_encode($after['server_only']));
$all = [...$sites, ...$server, 'env.dns.operate', 'env.dns.read'];
sort($all);
env_assert($env($after['both']) === $all && $env($after[SystemRolePresetSynchronizer::ADMINISTRATOR]) === $all, 'Both, and the administrator: all sixteen.');
env_assert($env($after[SystemRolePresetSynchronizer::EDITOR]) === $sites, 'The editor preset: every env.sites.* right, no server. '.json_encode($env($after['editor'])));
env_assert($after['readers'] === $before['readers'] && $after['neither'] === $before['neither'] && $env($after[SystemRolePresetSynchronizer::READER]) === [],
    'Roles without a broad right, and the reader preset, are unchanged.');

// Every declared operation, for every role: allowed after exactly when its route's old right was held before.
$differences = [];
foreach ($before as $code => $held) {
    foreach (EnvOperationCatalog::names() as $operation) {
        $right = EnvOperationCatalog::right($operation);
        $was = in_array(EnvOperationCatalog::SPLIT_FROM[$right] ?? $right, $held, true);
        $is = in_array($right, $after[$code], true);
        if ($was !== $is) {
            $differences[] = $code.' '.$operation.' '.($was ? 'lost' : 'gained');
        }
    }
}
env_assert($differences === [], 'Nobody loses or gains an operation: '.implode(', ', array_slice($differences, 0, 5)));

// A second update changes nothing; a right taken away afterwards stays away.
$revision = $store->role('sites_only')['revision'];
(new SystemRolePresetSynchronizer($new, $store))->synchronizeForLifecycle();
env_assert($store->role('sites_only')['revision'] === $revision, 'A second sync changes nothing.');
$store->updateCustomRole('sites_only', $revision, 'Sites_only', null, array_values(array_diff($after['sites_only'], ['env.sites.remove'])), 'actor:test');
(new SystemRolePresetSynchronizer($new, $store))->synchronizeForLifecycle();
env_assert(!in_array('env.sites.remove', $store->role('sites_only')['operations'], true), 'A right taken away after the split stays away.');

echo "RightSplitTest passed.\n";
