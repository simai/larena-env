<?php

declare(strict_types=1);

// The declarations match the runtime (auditor msg-3d61bd36, point 3): one source, EnvDeclarations, and a gate that fails
// on any difference between it and access.yaml, audit.yaml, module.yaml, the rights the provider registers and the code
// asks, and the events the code writes. Each kind of drift below is caught; PanelAudit refuses an undeclared event.

use Illuminate\Container\Container;
use Larena\Audit\Contracts\AuditSink;
use Larena\Audit\Runtime\InMemoryAuditSink;
use Larena\Env\People\PanelAudit;
use Larena\Env\Providers\EnvDeclarations;

require_once dirname(__DIR__).'/Support/env-test.php';
$_SERVER['SCRIPT_FILENAME'] = __FILE__;
require_once dirname(__DIR__, 2).'/scripts/check-declarations.php';

$package = dirname(__DIR__, 2);
env_assert(env_declaration_differences($package) === [], 'The package agrees with itself: '.json_encode(env_declaration_differences($package)));

/** A copy of the package's declarations beside its real code, with one file changed. */
$copy = static function (string $file, callable $change) use ($package): string {
    // A folder of its own: its links to the package's code are unlinked at the end, never followed.
    $root = sys_get_temp_dir().'/larena-env-declarations-'.bin2hex(random_bytes(6));
    mkdir($root, 0700);
    register_shutdown_function(static function () use ($root): void {
        foreach (['src', 'routes', 'config'] as $folder) {
            if (is_link($root.'/'.$folder)) {
                unlink($root.'/'.$folder);
            }
        }
        foreach (['access.yaml', 'audit.yaml', 'module.yaml', 'operations.yaml'] as $name) {
            if (is_file($root.'/'.$name)) {
                unlink($root.'/'.$name);
            }
        }
        rmdir($root);
    });
    foreach (['access.yaml', 'audit.yaml', 'module.yaml', 'operations.yaml'] as $name) {
        file_put_contents($root.'/'.$name, $name === $file ? $change((string) file_get_contents($package.'/'.$name)) : (string) file_get_contents($package.'/'.$name));
    }
    foreach (['src', 'routes', 'config'] as $folder) {
        symlink($package.'/'.$folder, $root.'/'.$folder);
    }

    return $root;
};
$finds = static function (string $root, string $text): bool {
    foreach (env_declaration_differences($root) as $error) {
        if (str_contains($error, $text)) {
            return true;
        }
    }

    return false;
};

// module.yaml as it was before (two rights, no events): both lists are caught.
$old = $copy('module.yaml', static fn (string $yaml): string => (string) preg_replace('/access_operations:.*?(?=evidence:)/s', "access_operations:\n  - env.server.read\n  - env.server.operate\naudit_events: []\n", $yaml));
env_assert($finds($old, 'module.yaml access_operations: missing env.server.software') && $finds($old, 'module.yaml audit_events: missing'), 'module.yaml behind the source fails.');
// access.yaml without the sites' rights, as it was (four): caught; a changed risk too.
$fewer = $copy('access.yaml', static fn (string $yaml): string => (string) preg_replace('/  # The sites a person.*?(?=  # DNS)/s', '', $yaml));
env_assert($finds($fewer, 'access.yaml operations: missing env.sites.read, env.sites.operate'), 'access.yaml with four rights fails.');
$risk = $copy('access.yaml', static fn (string $yaml): string => preg_replace('/(code: env\.dns\.operate.*?risk: )high/s', '$1normal', $yaml, 1) ?? $yaml);
env_assert($finds($risk, 'access.yaml env.dns.operate: risk'), 'A right declared with another risk fails.');
// audit.yaml with an event nobody writes, or without one the code writes, or another severity.
$extra = $copy('audit.yaml', static fn (string $yaml): string => str_replace("forbidden_payload_fields:", "  - type: env.nothing.happened\n    severity: notice\nforbidden_payload_fields:", $yaml));
env_assert($finds($extra, 'audit.yaml events: not declared env.nothing.happened'), 'An event in audit.yaml the source does not name fails.');
$missing = $copy('audit.yaml', static fn (string $yaml): string => str_replace("  - type: env.server.change_started\n    severity: notice\n", '', $yaml));
env_assert($finds($missing, 'audit.yaml events: missing env.server.change_started'), 'An event the code writes, missing from audit.yaml, fails.');
$severity = $copy('audit.yaml', static fn (string $yaml): string => str_replace("  - type: env.person.removed\n    severity: notice", "  - type: env.person.removed\n    severity: info", $yaml));
env_assert($finds($severity, 'audit.yaml env.person.removed: severity'), 'Another severity fails.');

// Code that writes an event, or asks a right, the source does not name.
$code = env_temp_directory();
foreach (['access.yaml', 'audit.yaml', 'module.yaml'] as $name) {
    copy($package.'/'.$name, $code.'/'.$name);
}
mkdir($code.'/src');
mkdir($code.'/routes');
mkdir($code.'/config');
file_put_contents($code.'/src/Stray.php', "<?php\nnamespace Larena\\Env;\nfinal class Stray { public function run(\$audit, \$access, \$request): void {\n"
    ."    \$audit->record('env.stray.done', 'a', 'b');\n    \$access->authorize(\$request, 'env.stray.read');\n} }\n");
env_assert($finds($code, 'events the code writes, not declared: env.stray.done') && $finds($code, 'rights the code asks for, not declared: env.stray.read'),
    'An undeclared event written or right asked fails: '.json_encode(env_declaration_differences($code)));
env_assert($finds($code, 'events the code writes: missing env.person.owner_assigned'), 'A declared event no code writes fails.');

// At runtime: PanelAudit writes only what is declared.
$host = new Container();
$sink = new InMemoryAuditSink();
$host->instance(AuditSink::class, $sink);
$audit = new PanelAudit($host);
$audit->record('env.person.blocked', 'user:admin_identity:1', 'user:admin_identity:2');
try {
    $audit->record('env.stray.done', 'user:admin_identity:1', 'x');
    env_assert(false, 'An undeclared event is refused.');
} catch (InvalidArgumentException $refused) {
    env_assert(str_contains($refused->getMessage(), 'env_audit_event_undeclared'), 'Refused by name.');
}
env_assert(count($sink->events()) === 1, 'Only the declared event was written.');

// The panel's changes as Core operations (point 4): operations.yaml edited by hand is caught, as is a right taken from it.
$edited = $copy('operations.yaml', static fn (string $yaml): string => str_replace("name: env.site.remove\n    execution_mode: sync\n    risk: external\n    reversible: true\n    access_scope: env.sites.remove",
    "name: env.site.remove\n    execution_mode: sync\n    risk: external\n    reversible: true\n    access_scope: env.sites.operate", $yaml));
env_assert($finds($edited, 'operations.yaml differs') && $finds($edited, 'Core refuses the operations'), 'operations.yaml edited by hand fails, and Core refuses the pair that disagrees.');
// A screen that sends a command string, reaches PanelOperations, or names an operation the catalog lacks: each caught.
$said = static fn (string $relative, string $code): string => implode(' | ', env_operation_code_differences($relative, $code));
env_assert(str_contains($said('src/Http/Controllers/X.php', '$this->operations->planCommand($actor, \'site:add\', $args);'), 'plans or starts a command string')
    && str_contains($said('src/Http/Controllers/X.php', 'use Larena\\Env\\Operations\\PanelOperations;'), 'reaches PanelOperations')
    && str_contains($said('src/Http/Controllers/X.php', '$this->operations->start($actor, \'env.site.explode\', $args);'), 'env.site.explode')
    && $said('src/Http/Controllers/X.php', '$this->operations->start($actor, \'env.site.add\', $args);') === ''
    && $said('src/Operations/GatewayOperationHandler.php', '$panel->startCommand($actor, $command, $args);') === '',
    'Only the handler starts a command string; the screens name known operations.');
// The count is PanelCommands::names(), never the file's key lines: 162 commands, 112 actions, 4 job commands, while the
// file also names commands in ARGUMENTS_SINCE (9) and ATTACHMENTS (1), 172 key lines in all.
$keyLines = preg_match_all("/^\\s*'[a-z]+:[a-z-]+' *=>/m", (string) file_get_contents($package.'/src/Gateway/PanelCommands.php'));
env_assert(count(Larena\Env\Gateway\PanelCommands::names()) === 162 && count(Larena\Env\Gateway\PanelCommands::actions()) === 112 && $keyLines === 172,
    'Commands are counted by name: '.count(Larena\Env\Gateway\PanelCommands::names()).' names, '.$keyLines.' key lines.');

// The features (point 5): every right in exactly one feature, requirements and rights declared, no cycle.
$features = Larena\Env\Providers\EnvDeclarations::FEATURES;
$rights = Larena\Env\Providers\EnvDeclarations::RIGHTS;
env_assert(env_feature_differences($features, $rights) === [], 'The features agree: '.json_encode(env_feature_differences($features, $rights)));
$orphan = $features;
$orphan['env.dns']['operations'] = ['env.dns.read'];
$twice = $features;
$twice['env.admin']['operations'][] = 'env.dns.read';
$unknown = $features;
$unknown['env.dns']['requires'] = ['env.panel'];
$cycle = $features;
$cycle['env.admin']['requires'] = ['env.dns'];
$said = static fn (array $changed): string => implode(' | ', env_feature_differences($changed, $rights));
env_assert(str_contains($said($orphan), 'right env.dns.operate: in 0 features') && str_contains($said($twice), 'right env.dns.read: in 2 features')
    && str_contains($said($unknown), 'requires an undeclared feature env.panel') && str_contains($said($cycle), 'larena_feature_cycle'),
    'A right in no feature or in two, an unknown requirement and a cycle fail: '.$said($orphan).' / '.$said($cycle));

echo "DeclarationsTest passed.\n";
