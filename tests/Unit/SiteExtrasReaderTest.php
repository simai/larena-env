<?php

declare(strict_types=1);

// The reads Panel plan stage 3 adds, typed from the answers simai-env was asked for: PHP settings and extensions, the
// site's databases, its SFTP accounts and the server's load. A refusal or a failure is null, never a guess.

use Larena\Env\Contracts\PanelGateway;
use Larena\Env\Gateway\PanelActor;
use Larena\Env\Gateway\PanelAnswer;
use Larena\Env\Gateway\PanelRequest;
use Larena\Env\Server\SiteExtrasReader;

require_once dirname(__DIR__).'/Support/env-test.php';

/** @param array<string, array<string, mixed>> $documents answers by command */
function env_extras_answering(array $documents): SiteExtrasReader
{
    return new SiteExtrasReader(new class($documents) implements PanelGateway {
        /** @param array<string, array<string, mixed>> $documents */
        public function __construct(private array $documents)
        {
        }

        public function ask(PanelRequest $request): PanelAnswer
        {
            return PanelAnswer::fromDocument($request->requestId, $this->documents[$request->command] ?? ['status' => 'refused', 'reason' => 'not_allowed']);
        }

        public function source(): string
        {
            return 'test';
        }
    });
}

$ok = static fn (array $data): array => ['status' => 'ok', 'kind' => 'result', 'data' => $data];
$reader = env_extras_answering([
    'site:php-ini-list' => $ok(['settings' => [['key' => 'memory_limit', 'value' => '256M', 'source' => 'site'], ['key' => 'upload_max_filesize', 'value' => 64], ['value' => 'no key']]]),
    'php:extensions' => $ok(['php' => '8.3', 'extensions' => [['name' => 'intl', 'enabled' => true], ['name' => 'imagick', 'enabled' => false]]]),
    'db:list' => $ok(['databases' => [
        ['name' => 'shop', 'engine' => 'mysql', 'site' => 'shop.test', 'size_bytes' => 1024, 'users' => ['shop'], 'password' => 'never'],
        ['name' => 'blog', 'engine' => 'mysql', 'site' => 'blog.test', 'size_bytes' => 2048, 'users' => []],
    ]]),
    'access:list' => $ok(['schema' => 'simai-access/1', 'accesses' => [
        ['login' => 'dev', 'type' => 'project', 'domain' => 'shop.test', 'enabled' => false, 'auth' => 'key', 'sftp_path' => '/site', 'created_at' => '2026-01-01T00:00:00'],
        ['login' => 'blogger', 'type' => 'project', 'domain' => 'blog.test', 'enabled' => true, 'auth' => 'key'],
        ['login' => 'Bad Login', 'domain' => 'shop.test'],
    ]]),
    'self:metrics' => $ok(['cpu' => ['cores' => 4, 'load1' => 1, 'load5' => 0.5, 'load15' => 0.25], 'memory' => ['total_bytes' => 100, 'used_bytes' => 50], 'disk' => []]),
]);
$actor = new PanelActor('admin@example.com');

$settings = $reader->phpSettings($actor, 'shop.test');
env_assert(is_array($settings) && count($settings) === 2 && $settings[0]->key === 'memory_limit' && $settings[1]->value === '64', 'Settings; a number is shown as text, a line without a key is left out.');
$extensions = $reader->phpExtensions($actor, '8.3');
env_assert(is_array($extensions) && count($extensions) === 2 && $extensions[1]->enabled === false, 'Extensions, on and off.');
$databases = $reader->databases($actor, 'shop.test');
env_assert(is_array($databases) && count($databases) === 1 && $databases[0]->name === 'shop' && $databases[0]->sizeBytes === 1024, 'Only this site\'s databases.');
env_assert(!str_contains(serialize($databases), 'never'), 'A password the server sent anyway is not kept.');
$accounts = $reader->sftpAccounts($actor, 'shop.test');
env_assert(is_array($accounts) && count($accounts) === 1 && $accounts[0]->login === 'dev' && $accounts[0]->enabled === false
    && $accounts[0]->auth === 'key' && $accounts[0]->path === '/site', 'Only this site\'s accounts; one with an impossible login is left out.');
$metrics = $reader->metrics($actor);
env_assert($metrics !== null && $metrics->cores === 4 && $metrics->load === [1.0, 0.5, 0.25] && $metrics->memoryUsed === 50 && $metrics->swapTotal === null, 'The load now.');

$refusing = env_extras_answering([]);
env_assert($refusing->phpSettings($actor, 'shop.test') === null && $refusing->databases($actor, 'shop.test') === null && $refusing->metrics($actor) === null, 'A refusal is null.');
env_assert($reader->phpSettings($actor, 'not a domain') === null, 'What the gateway would refuse is not sent.');

echo "SiteExtrasReaderTest passed.\n";
