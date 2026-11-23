<?php

declare(strict_types=1);

// Writes operations.yaml from its one source (auditor msg-3d61bd36, point 4): EnvOperationCatalog for the operations and
// their rights, PanelCommands for each command's arguments. declarations:check fails when the file differs.
//
//   php scripts/write-operations.php [root]

use Larena\Env\Operations\EnvOperationContract;
use Symfony\Component\Yaml\Yaml;

require_once dirname(__DIR__).'/vendor/autoload.php';

/** The file's text: a header that says where it comes from, then the operations, a property per line. */
function env_operations_yaml(): string
{
    return "# The panel's changes as Core operations (larena/env). Do not edit: written by `composer run operations:write`\n"
        ."# from src/Operations/EnvOperationCatalog.php and src/Gateway/PanelCommands.php; declarations:check fails on any\n"
        ."# difference. Patterns are the gateway's own, as PanelCommands writes them.\n"
        .Yaml::dump(EnvOperationContract::document(), 5, 2, Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE);
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    $root = $argv[1] ?? dirname(__DIR__);
    file_put_contents($root.'/operations.yaml', env_operations_yaml());
    echo "operations.yaml written.\n";
}
