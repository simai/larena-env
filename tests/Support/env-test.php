<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2).'/vendor/autoload.php';

function env_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function env_recorded_path(): string
{
    return dirname(__DIR__, 2).'/resources/recorded-answers';
}

function env_recorded_bytes(string $file): string
{
    return (string) file_get_contents(env_recorded_path().'/'.$file);
}

/** A disposable folder, removed when the test ends. */
function env_temp_directory(): string
{
    $directory = sys_get_temp_dir().'/larena-env-test-'.bin2hex(random_bytes(6));
    mkdir($directory, 0700);
    register_shutdown_function(static function () use ($directory): void {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($directory);
    });

    return $directory;
}

/**
 * The plan receipts on a clock the test moves (seconds): `$now += 901` makes every receipt given before it old.
 */
function env_plan_receipts(int &$now): Larena\Env\Http\Support\PlanReceipts
{
    return new Larena\Env\Http\Support\PlanReceipts(900, static function () use (&$now): int {
        return $now;
    });
}

/**
 * The site view's data as the controller gives it: what SiteCard adds from the rest (the card, the check's problems,
 * the section's row of actions), so a screen of tests draws the page the controller draws.
 *
 * @param array<string, mixed> $data
 * @return array<string, mixed>
 */
function env_site_view(array $data): array
{
    return $data + \Larena\Env\Admin\SiteCard::forView($data, static fn (string $key, array $replace = []): string => (string) __('larena-env::admin.'.$key, $replace),
        route('larena.env.admin.site', ['domain' => (string) ($data['domain'] ?? '')], false));
}
