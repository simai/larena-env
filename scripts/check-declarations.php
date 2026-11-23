<?php

declare(strict_types=1);

// The package's declarations match what it does (auditor msg-3d61bd36, point 3). One source: EnvDeclarations. This fails
// on any difference between it and access.yaml, audit.yaml or module.yaml, and on any right or audit event the code
// registers, asks or writes that the source does not name — or one the source names and the code never uses.
//
//   php scripts/check-declarations.php [root]

use Larena\Access\Runtime\AccessOperationRegistry;
use Larena\Env\Navigation\ServerAdminNavigationContributor;
use Larena\Env\Providers\EnvDeclarations;
use Larena\Env\Providers\EnvServiceProvider;
use Symfony\Component\Yaml\Yaml;

require_once dirname(__DIR__).'/vendor/autoload.php';

/**
 * Every difference, in words; an empty list when everything agrees.
 *
 * @return list<string>
 */
function env_declaration_differences(string $root): array
{
    $errors = [];
    $rights = EnvDeclarations::RIGHTS;
    $events = EnvDeclarations::events();
    $same = static function (string $what, array $declared, array $found) use (&$errors): void {
        $missing = array_values(array_diff($declared, $found));
        $extra = array_values(array_diff($found, $declared));
        if ($missing !== [] || $extra !== []) {
            $errors[] = $what.': '.($missing !== [] ? 'missing '.implode(', ', $missing) : '').($missing !== [] && $extra !== [] ? '; ' : '')
                .($extra !== [] ? 'not declared '.implode(', ', $extra) : '');
        } elseif ($declared !== $found) {
            $errors[] = $what.': not in the order of EnvDeclarations';
        }
    };
    $yaml = static function (string $file) use ($root, &$errors): array {
        try {
            $parsed = Yaml::parseFile($root.'/'.$file);
        } catch (Throwable $failure) {
            $errors[] = $file.': unreadable ('.$failure->getMessage().')';

            return [];
        }

        return is_array($parsed) ? $parsed : [];
    };

    // access.yaml: every right, field by field.
    $access = $yaml('access.yaml');
    $operations = is_array($access['operations'] ?? null) ? $access['operations'] : [];
    $same('access.yaml operations', array_keys($rights), array_map(static fn (mixed $row): string => is_array($row) ? (string) ($row['code'] ?? '') : '', $operations));
    foreach ($operations as $row) {
        $code = is_array($row) ? (string) ($row['code'] ?? '') : '';
        if (isset($rights[$code])) {
            foreach ($rights[$code] as $field => $value) {
                if ((string) ($row[$field] ?? '') !== $value) {
                    $errors[] = 'access.yaml '.$code.': '.$field.' is '.json_encode($row[$field] ?? null).', EnvDeclarations says '.json_encode($value);
                }
            }
        }
    }
    if (($access['package'] ?? null) !== 'larena/env') {
        $errors[] = 'access.yaml: package is not larena/env';
    }

    // audit.yaml: every event with its severity.
    $audit = $yaml('audit.yaml');
    $declared = [];
    foreach (is_array($audit['events'] ?? null) ? $audit['events'] : [] as $row) {
        $type = is_array($row) ? (string) ($row['type'] ?? '') : '';
        $declared[] = $type;
        if (isset($events[$type]) && (string) ($row['severity'] ?? '') !== $events[$type]) {
            $errors[] = 'audit.yaml '.$type.': severity is '.json_encode($row['severity'] ?? null).', EnvDeclarations says '.json_encode($events[$type]);
        }
    }
    $same('audit.yaml events', array_keys($events), $declared);

    // module.yaml: the two lists.
    $module = $yaml('module.yaml');
    $same('module.yaml access_operations', array_keys($rights), array_map('strval', is_array($module['access_operations'] ?? null) ? $module['access_operations'] : []));
    $same('module.yaml audit_events', array_keys($events), array_map('strval', is_array($module['audit_events'] ?? null) ? $module['audit_events'] : []));

    // What the provider registers at runtime: exactly the declared rights, with their fields.
    $registry = new AccessOperationRegistry();
    if (!EnvServiceProvider::registerAccessOperations($registry)) {
        $errors[] = 'runtime: a right was not registered';
    }
    $registered = [];
    foreach ($registry->all() as $descriptor) {
        if ($descriptor->ownerPackage !== 'larena/env') {
            continue;
        }
        $registered[] = $descriptor->code;
        $right = $rights[$descriptor->code] ?? null;
        if ($right !== null && [$descriptor->labelKey, $descriptor->target, $descriptor->requiredGrant, $descriptor->risk, $descriptor->auditDenials]
            !== [$right['label'], $right['target'], $right['grant'], $right['risk'], EnvDeclarations::auditsDenials($right['audit'])]) {
            $errors[] = 'runtime '.$descriptor->code.': registered differently from EnvDeclarations';
        }
    }
    $sorted = array_keys($rights);
    sort($sorted);
    // Access lists what it registered by package and code.
    $same('runtime rights', $sorted, $registered);

    // What the menu registers: each row's right is declared, and its view event is exactly the declared view events.
    $navigation = (new ServerAdminNavigationContributor(static fn (): bool => true))->navigationDescriptors();
    $scopes = array_values(array_unique(array_map(static fn ($row): string => $row->accessScope, $navigation)));
    $undeclared = array_values(array_diff($scopes, array_keys($rights)));
    if ($undeclared !== []) {
        $errors[] = 'navigation rights not declared: '.implode(', ', $undeclared);
    }
    $viewed = array_values(array_unique(array_map(static fn ($row): string => $row->auditEvent, $navigation)));
    $same('navigation view events', array_keys(EnvDeclarations::VIEW_EVENTS), $viewed);

    // The features (point 5): every right in exactly one feature, each feature's rights and requirements declared, core's
    // FeatureSet takes them (no cycle), the menu's feature rows name declared features, and the panel's edition turns on
    // exactly the declared ones.
    $errors = [...$errors, ...env_feature_differences(EnvDeclarations::FEATURES, $rights)];

    // What the code asks and writes: the rights it authorizes and routes behind `access:`, the events it records.
    [$asked, $written] = env_declaration_uses($root);
    $undeclared = array_values(array_diff($asked, array_keys($rights)));
    if ($undeclared !== []) {
        $errors[] = 'rights the code asks for, not declared: '.implode(', ', $undeclared);
    }
    $unused = array_values(array_diff(array_keys($rights), $asked, $scopes));
    if ($unused !== []) {
        $errors[] = 'declared rights the code never asks for: '.implode(', ', $unused);
    }
    $same('events the code writes', array_keys(EnvDeclarations::AUDIT_EVENTS), array_values(array_intersect(array_keys(EnvDeclarations::AUDIT_EVENTS), $written)));
    $undeclared = array_values(array_diff($written, array_keys(EnvDeclarations::AUDIT_EVENTS)));
    if ($undeclared !== []) {
        $errors[] = 'events the code writes, not declared: '.implode(', ', $undeclared);
    }

    return $errors;
}

/**
 * The features against the rights, the menu and the panel's edition (point 5).
 *
 * @param array<string, array{requires: list<string>, operations: list<string>}> $features
 * @param array<string, array<string, string>> $rights
 *
 * @return list<string>
 */
function env_feature_differences(array $features, array $rights): array
{
    $errors = [];
    $owned = [];
    foreach ($features as $name => $feature) {
        if (!str_starts_with($name, 'env.')) {
            $errors[] = 'feature '.$name.': not of env';
        }
        foreach ($feature['requires'] as $required) {
            if (!isset($features[$required])) {
                $errors[] = 'feature '.$name.': requires an undeclared feature '.$required;
            }
        }
        foreach ($feature['operations'] as $right) {
            if (!isset($rights[$right])) {
                $errors[] = 'feature '.$name.': names an undeclared right '.$right;
            }
            $owned[$right][] = $name;
        }
    }
    foreach (array_keys($rights) as $right) {
        if (count($owned[$right] ?? []) !== 1) {
            $errors[] = 'right '.$right.': in '.count($owned[$right] ?? []).' features, not exactly one';
        }
    }
    try {
        $set = new \Larena\Core\Features\FeatureSet();
        $set->declare('larena/env', $features);
        $set->validate();
    } catch (Throwable $failure) {
        $errors[] = 'features: core\'s FeatureSet refuses them ('.$failure->getMessage().')';
    }
    foreach (ServerAdminNavigationContributor::FEATURES as $row => $feature) {
        if (!isset($features[$feature])) {
            $errors[] = 'navigation row '.$row.': names an undeclared feature '.$feature;
        }
    }
    $edition = \Larena\Env\Providers\PanelEditionServiceProvider::FEATURES;
    sort($edition);
    $declaredFeatures = array_keys($features);
    sort($declaredFeatures);
    if ($edition !== $declaredFeatures) {
        $errors[] = 'the panel\'s edition turns on '.implode(', ', $edition).', not every declared feature';
    }

    return $errors;
}

/**
 * The rights the code asks for — `access:<right>` in routes, config and code, and the literal rights of an
 * `authorize(…)` or `allows(…)` call — and the event types it writes: the first argument of every `->record(…)` call,
 * a literal or a class constant (`self::X`, `PanelOperations::X`).
 *
 * @return array{0: list<string>, 1: list<string>}
 */
function env_declaration_uses(string $root): array
{
    $asked = [];
    $written = [];
    $files = [];
    foreach (['src', 'routes', 'config'] as $folder) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$folder, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
    }
    sort($files);
    foreach ($files as $path) {
        $code = (string) file_get_contents($path);
        preg_match_all('/[\'"]access:(env\.[a-z_]+\.[a-z_]+)[\'"]/', $code, $routes);
        $asked = [...$asked, ...$routes[1]];
        $tokens = token_get_all($code);
        $namespace = '';
        $class = null;
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (is_array($token) && $token[0] === T_NAMESPACE) {
                for ($j = $i + 1; $j < $count && $tokens[$j] !== ';' && $tokens[$j] !== '{'; $j++) {
                    $namespace .= is_array($tokens[$j]) && in_array($tokens[$j][0], [T_NAME_QUALIFIED, T_STRING], true) ? $tokens[$j][1] : '';
                }
            }
            if (is_array($token) && $token[0] === T_CLASS && $class === null) {
                for ($j = $i + 1; $j < $count; $j++) {
                    if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                        $class = $namespace.'\\'.$tokens[$j][1];
                        break;
                    }
                }
            }
            if (!is_array($token) || $token[0] !== T_STRING || !in_array($token[1], ['record', 'authorize', 'allows'], true)) {
                continue;
            }
            $before = $tokens[$i - 1] ?? null;
            if (!is_array($before) || !in_array($before[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)) {
                continue;
            }
            $open = $i + 1;
            while ($open < $count && is_array($tokens[$open]) && $tokens[$open][0] === T_WHITESPACE) {
                $open++;
            }
            if (($tokens[$open] ?? null) !== '(') {
                continue;
            }
            // The call's arguments: the first for an event, all for a right.
            $depth = 0;
            $argument = 0;
            for ($j = $open; $j < $count; $j++) {
                $part = $tokens[$j];
                if (in_array($part, ['(', '[', '{'], true)) {
                    $depth++;
                } elseif (in_array($part, [')', ']', '}'], true)) {
                    if (--$depth === 0) {
                        break;
                    }
                } elseif ($part === ',' && $depth === 1) {
                    $argument++;
                }
                if (!is_array($part)) {
                    continue;
                }
                $value = null;
                if ($part[0] === T_CONSTANT_ENCAPSED_STRING) {
                    $value = stripslashes(substr($part[1], 1, -1));
                } elseif ($part[0] === T_DOUBLE_COLON && $class !== null) {
                    $owner = $tokens[$j - 1] ?? null;
                    $name = $tokens[$j + 1] ?? null;
                    if (is_array($owner) && is_array($name) && $name[0] === T_STRING) {
                        $target = in_array($owner[1], ['self', 'static'], true) ? $class : env_declaration_class($owner[1], $code, $namespace);
                        $value = defined($target.'::'.$name[1]) ? constant($target.'::'.$name[1]) : null;
                    }
                }
                if (!is_string($value) || preg_match('/\Aenv\.[a-z_]+\.[a-z_]+\z/D', $value) !== 1) {
                    continue;
                }
                if ($token[1] === 'record' && $argument === 0) {
                    $written[] = $value;
                } elseif ($token[1] !== 'record') {
                    $asked[] = $value;
                }
            }
        }
    }

    return [array_values(array_unique($asked)), array_values(array_unique($written))];
}

/** A short class name as the file imports it, or as it is written when it is whole. */
function env_declaration_class(string $name, string $code, string $namespace): string
{
    if (str_contains($name, '\\')) {
        return ltrim($name, '\\');
    }
    if (preg_match('/^use\s+([A-Za-z0-9_\\\\]+\\\\'.preg_quote($name, '/').')\s*;/m', $code, $match) === 1) {
        return $match[1];
    }

    return $namespace.'\\'.$name;
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    $root = $argv[1] ?? dirname(__DIR__);
    $errors = env_declaration_differences($root);
    if ($errors !== []) {
        fwrite(STDERR, "The declarations do not match the code (one source: src/Providers/EnvDeclarations.php):\n- ".implode("\n- ", $errors)."\n");
        exit(1);
    }
    echo 'Declarations match the code: '.count(EnvDeclarations::FEATURES).' features, '.count(EnvDeclarations::RIGHTS).' rights, '.count(EnvDeclarations::events())." audit events.\n";
}
