<?php

declare(strict_types=1);

$errors = [];
foreach ([
    '.gitignore', '.env.example', '.github/workflows/larena-package-ci.yml', '.githooks/pre-commit', '.githooks/pre-push',
    'composer.json', 'composer.lock', 'module.yaml', 'access.yaml', 'audit.yaml', 'README.md', 'CHANGELOG.md', 'phpstan.neon.dist',
    '.larena/spec-ref.json', '.larena/launch-context.json', 'tools/larena-scope-check.php',
    'src/Contracts/PanelGateway.php', 'src/Gateway/RecordedAnswersGateway.php', 'src/Gateway/SshGateway.php',
    'src/Server/ServerOverviewReader.php', 'src/Area/ServerArea.php', 'src/Providers/EnvServiceProvider.php',
    'resources/recorded-answers/manifest.json', 'resources/views/admin/index.blade.php', 'routes/admin.php',
] as $file) {
    if (!is_file($file)) {
        $errors[] = "Missing required file: {$file}";
    }
}
$specRef = json_decode((string) file_get_contents('.larena/spec-ref.json'), true, 512, JSON_THROW_ON_ERROR);
$context = json_decode((string) file_get_contents('.larena/launch-context.json'), true, 512, JSON_THROW_ON_ERROR);
$composer = json_decode((string) file_get_contents('composer.json'), true, 512, JSON_THROW_ON_ERROR);
if (($specRef['canonical_update_allowed'] ?? null) !== false) {
    $errors[] = '.larena/spec-ref.json must keep canonical_update_allowed=false';
}
if (($context['package'] ?? null) !== 'larena/env' || ($composer['name'] ?? null) !== 'larena/env') {
    $errors[] = 'The launch context and composer.json must name larena/env.';
}
if (($composer['extra']['laravel']['providers'] ?? []) !== ['Larena\\Env\\Providers\\EnvServiceProvider']) {
    $errors[] = 'composer.json must auto-discover EnvServiceProvider.';
}
if (isset($composer['require']['larena/admin'])) {
    $errors[] = 'larena/admin must stay optional.';
}
$view = (string) file_get_contents('resources/views/admin/index.blade.php');
if (preg_match('/<style\b|<script\b|@php\b/i', $view) === 1) {
    $errors[] = 'The view must not contain inline style, script or PHP.';
}
// The package reaches the host only through the core executor: no shell helper anywhere in its code.
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator('src', FilesystemIterator::SKIP_DOTS)) as $file) {
    if ($file instanceof SplFileInfo && preg_match('/\b(?:shell_exec|exec|system|passthru|popen|proc_open|escapeshellarg)\s*\(/', (string) file_get_contents($file->getPathname())) === 1) {
        $errors[] = "Direct process call in {$file->getPathname()}";
    }
}
if ($errors !== []) {
    fwrite(STDERR, implode(PHP_EOL, $errors).PHP_EOL);
    exit(1);
}
echo "Larena Env launch context is valid.\n";
