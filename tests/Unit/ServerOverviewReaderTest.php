<?php

declare(strict_types=1);

// The first screen's data: typed from the recording, without what the screen does not need; a refusal is a reason code.

use Larena\Env\Contracts\PanelGateway;
use Larena\Env\Gateway\PanelActor;
use Larena\Env\Gateway\PanelAnswer;
use Larena\Env\Gateway\PanelRequest;
use Larena\Env\Gateway\RecordedAnswersGateway;
use Larena\Env\Gateway\UnconfiguredGateway;
use Larena\Env\Server\ServerOverviewReader;

require_once dirname(__DIR__).'/Support/env-test.php';

/** @param array<string, mixed> $document */
function env_reader_answering(array $document): ServerOverviewReader
{
    return new ServerOverviewReader(new class($document) implements PanelGateway {
        /** @param array<string, mixed> $document */
        public function __construct(private array $document)
        {
        }

        public function ask(PanelRequest $request): PanelAnswer
        {
            return PanelAnswer::fromDocument($request->requestId, $this->document);
        }

        public function source(): string
        {
            return 'test';
        }
    });
}

$recorded = new ServerOverviewReader(new RecordedAnswersGateway(env_recorded_path()));

$result = $recorded->read(new PanelActor('admin@example.com'));
$overview = $result->overview;
env_assert($overview !== null && $result->reason === null && $result->source === 'recorded', 'The recording reads.');
env_assert($overview->envVersion === '1.32.0' && $overview->osId === 'ubuntu' && $overview->osVersion === '24.04', 'The server\'s status is typed.');
env_assert($overview->phpVersions === ['8.3'], 'PHP versions.');
$states = [];
foreach ($overview->services as $service) {
    $states[$service->name] = $service->isActive();
}
env_assert($states['nginx'] === true && $states['postgresql'] === false, 'Service states.');

$domains = array_map(static fn ($site): string => $site->domain, $overview->sites);
env_assert($domains === ['demo-owned.test', 'demo-php.test', 'demo-redirect.test', 'demo-static.test'], 'The sites list.');
[$owned, $php, $redirect, $static] = $overview->sites;
env_assert($owned->owner === 'demo' && $php->owner === null, 'Owners.');
env_assert($php->databaseEngine === 'mysql' && $owned->databaseEngine === null, 'Only the database\'s engine.');
env_assert($static->release === '20261101220014' && $php->release === null, 'The current release.');
env_assert($redirect->profile === 'redirect' && $redirect->php === 'none' && $redirect->ssl === 'none', 'A redirect site.');
// simai-env 1.27.0: whether a site answers, its disk, its last backup, and whether a space is blocked.
env_assert($owned->answersChecked && $owned->answers === 200 && $owned->diskBytes === 1048576 && $owned->lastBackup === null, 'Answers 200, takes 1 MB, no backup yet.');
env_assert($overview->owners === ['demo'] && $overview->ownerDiskBytes === ['demo' => 1048576] && $overview->blockedOwners === [], 'The space and that it is not blocked.');
env_assert($overview->capabilities !== null && $overview->capabilities->allows('site:set-php') && $overview->capabilities->allows('self:metrics')
    && $overview->capabilities->allows('access:create-project') && $overview->capabilities->allows('self:alerts')
    && $overview->capabilities->allows('self:metrics-history') && $overview->capabilities->allows('self:update-plan')
    && $overview->capabilities->allows('self:update') && !$overview->capabilities->allows('change:confirm'),
    'What the gateway allows, by the server\'s own list: the load, SFTP (1.30.0), its alerts and load history (1.31.0), safe updates (1.32.0); no timed changes yet.');
env_assert($overview->capabilities->takes('site:add', 'backup') && $overview->capabilities->takes('backup:data-schedule', 'verify'), 'A new site\'s backup and the check of each copy (1.30.0).');
env_assert(!\Larena\Env\Gateway\PanelCapabilities::fromHostDescription(['simai_env' => ['version' => '1.28.0'], 'gate' => ['commands' => ['site:add']]])->takes('site:add', 'backup'), 'An older server is not sent a new site\'s backup.');

$typed = serialize($overview);
foreach (['/home/simai', '/root/simai-env', 'demo_php_test', '"localhost"', 'agent_guide'] as $left) {
    env_assert(!str_contains($typed, $left), "The typed data must not carry {$left}.");
}

$own = $recorded->read(new PanelActor('demo@example.com', 'demo'));
env_assert($own->overview !== null && count($own->overview->sites) === 1 && $own->overview->sites[0]->domain === 'demo-owned.test', 'An owner\'s view.');

$certified = env_reader_answering(['status' => 'ok', 'kind' => 'result', 'data' => [
    'schema' => 'simai-host/1', 'generated_at' => 'now', 'simai_env' => ['version' => '1'], 'os' => ['id' => 'ubuntu', 'version' => '24.04'],
    'services' => [], 'php_versions' => [],
    'sites' => [['domain' => 'a.test', 'profile' => 'static', 'php' => 'none', 'ssl' => 'letsencrypt', 'certificate' => ['days_left' => 42]]],
]])->read(new PanelActor('a@b.c'));
env_assert($certified->overview !== null && $certified->overview->sites[0]->certificateDaysLeft === 42, 'A certificate\'s days left.');

// Refusals and failures.
// An unusable actor is refused before anything is asked: no actor can be made of it (PanelRequestTest).
foreach (["two\nlines", ''] as $badUser) {
    try {
        new PanelActor($badUser);
        env_assert(false, 'An unusable actor is refused.');
    } catch (\Larena\Env\Gateway\PanelRefused $refused) {
        env_assert($refused->reasonCode === 'bad_actor', 'An unusable actor is refused as bad_actor.');
    }
}

$refused = env_reader_answering(['status' => 'refused', 'reason' => 'owner_scope'])->read(new PanelActor('a@b.c'));
env_assert($refused->overview === null && $refused->refused && $refused->reason === 'owner_scope', 'A gateway refusal.');
$failed = env_reader_answering(['status' => 'failed', 'reason' => 'invalid_output'])->read(new PanelActor('a@b.c'));
env_assert($failed->overview === null && !$failed->refused && $failed->reason === 'invalid_output', 'A failure is not a refusal.');
$none = (new ServerOverviewReader(new UnconfiguredGateway()))->read(new PanelActor('a@b.c'));
env_assert($none->reason === 'gateway_not_configured' && !$none->refused && $none->source === 'none', 'No gateway.');
$plan = env_reader_answering(['status' => 'ok', 'kind' => 'plan', 'data' => []])->read(new PanelActor('a@b.c'));
env_assert($plan->reason === 'answer_shape_invalid', 'A read must answer a result.');
foreach ([
    'another schema' => ['schema' => 'simai-host/2'],
    'no sites' => ['schema' => 'simai-host/1', 'generated_at' => 'n', 'simai_env' => ['version' => '1'], 'os' => ['id' => 'u', 'version' => '1']],
    'a site without a domain' => ['schema' => 'simai-host/1', 'generated_at' => 'n', 'simai_env' => ['version' => '1'], 'os' => ['id' => 'u', 'version' => '1'], 'sites' => [['profile' => 'static']]],
    'a service state of the wrong type' => ['schema' => 'simai-host/1', 'generated_at' => 'n', 'simai_env' => ['version' => '1'], 'os' => ['id' => 'u', 'version' => '1'], 'services' => ['nginx' => 1], 'sites' => []],
] as $name => $data) {
    $shape = env_reader_answering(['status' => 'ok', 'kind' => 'result', 'data' => $data])->read(new PanelActor('a@b.c'));
    env_assert($shape->overview === null && $shape->reason === 'answer_shape_invalid', "{$name}: the shape is refused.");
}

echo "ServerOverviewReaderTest passed.\n";
