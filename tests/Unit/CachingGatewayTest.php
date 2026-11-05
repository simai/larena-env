<?php

declare(strict_types=1);

// The server's answers kept for a short while (owner decision 2026-11-05: a click answers within half a second): a read
// of the server's state is asked once per person within the ttl and once per page, an action or a look at a job makes
// every kept answer stale, logs and refusals are never kept.

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Larena\Env\Contracts\PanelGateway;
use Larena\Env\Gateway\CachingPanelGateway;
use Larena\Env\Gateway\PanelActor;
use Larena\Env\Gateway\PanelAnswer;
use Larena\Env\Gateway\PanelAnswerStatus;
use Larena\Env\Gateway\PanelRequest;

require_once dirname(__DIR__, 2).'/vendor/autoload.php';
require_once dirname(__DIR__).'/Support/env-test.php';

$server = new class () implements PanelGateway {
    /** @var list<string> */
    public array $asked = [];
    public bool $refuse = false;

    public function ask(PanelRequest $request): PanelAnswer
    {
        $this->asked[] = $request->command.($request->confirm ? '!' : '');
        if ($this->refuse) {
            return PanelAnswer::refused($request->requestId, 'not_allowed');
        }

        return PanelAnswer::fromDocument($request->requestId, ['status' => 'ok', 'kind' => 'result', 'data' => ['n' => count($this->asked)]]);
    }

    public function source(): string
    {
        return 'sudo';
    }
};
$cache = new Repository(new ArrayStore());
$admin = new PanelActor('admin@example.com');
$owner = new PanelActor('owner@example.com', 'demo');

$gateway = new CachingPanelGateway($server, $cache, 30);
$first = $gateway->ask(PanelRequest::read($admin, 'self:describe'));
$again = $gateway->ask(PanelRequest::read($admin, 'self:describe'));
env_assert($first->status === PanelAnswerStatus::Ok && $again->data === $first->data && $server->asked === ['self:describe'], 'The same read in one page is asked once.');

// The next page (a new gateway, the same cache) is answered from the cache.
$next = new CachingPanelGateway($server, $cache, 30);
env_assert($next->ask(PanelRequest::read($admin, 'self:describe'))->data === $first->data && count($server->asked) === 1, 'The next screen within the ttl does not ask the server.');
env_assert($next->ask(PanelRequest::read($admin, 'site:describe', ['domain' => 'a.test']))->status === PanelAnswerStatus::Ok
    && $next->ask(PanelRequest::read($admin, 'site:describe', ['domain' => 'b.test']))->data !== $next->ask(PanelRequest::read($admin, 'site:describe', ['domain' => 'a.test']))->data
    && count($server->asked) === 3, 'Each site\'s answer is its own.');

// An owner sees other answers than the admin: never the admin's kept answer.
$next->ask(PanelRequest::read($owner, 'self:describe'));
env_assert(count($server->asked) === 4, 'An owner is asked for their own answer.');

// Logs and job runs are asked every time.
$next->ask(PanelRequest::read($admin, 'service:log', ['name' => 'nginx']));
$next->ask(PanelRequest::read($admin, 'service:log', ['name' => 'nginx']));
env_assert(count($server->asked) === 6, 'A log is read from the server every time.');

// A confirmed action makes every kept answer stale, for every page after it.
$next->ask(PanelRequest::plan($admin, 'service:restart', ['name' => 'nginx']));
$planned = count($server->asked);
$kept = $next->ask(PanelRequest::read($admin, 'self:describe'));
env_assert($planned === 7 && $kept->data === $first->data && $server->asked[array_key_last($server->asked)] === 'service:restart', 'A plan changes nothing and keeps the answers.');
$next->ask(PanelRequest::confirmed($admin, 'service:restart', ['name' => 'nginx']));
$after = new CachingPanelGateway($server, $cache, 30);
$fresh = $after->ask(PanelRequest::read($admin, 'self:describe'));
env_assert($fresh->data !== $first->data && end($server->asked) === 'self:describe', 'After an action the server is asked again.');

// A look at a job does the same: the job may have changed the server.
$before = count($server->asked);
$after->ask(PanelRequest::read($admin, 'job:status', ['job_id' => str_repeat('a', 24)]));
$after->ask(PanelRequest::read($admin, 'self:describe'));
env_assert(count($server->asked) === $before + 2, 'After a look at a job the server is asked again.');

// A refusal is said again on the next screen, never kept.
$server->refuse = true;
$refused = new CachingPanelGateway($server, new Repository(new ArrayStore()), 30);
$refused->ask(PanelRequest::read($admin, 'firewall:status'));
$server->refuse = false;
env_assert($refused->ask(PanelRequest::read($admin, 'firewall:status'))->status === PanelAnswerStatus::Ok, 'A refusal is not kept.');

// A cache that holds something else is passed by.
$odd = new Repository(new ArrayStore());
$probe = new CachingPanelGateway($server, $odd, 30);
$probe->ask(PanelRequest::read($admin, 'php:list'));
env_assert(CachingPanelGateway::keeps(PanelRequest::read($admin, 'php:list')) && !CachingPanelGateway::keeps(PanelRequest::read($admin, 'file:read', ['domain' => 'a.test', 'path' => 'x']))
    && $probe->source() === 'sudo', 'Only the reads of the server\'s state are kept.');

echo "CachingGatewayTest passed.\n";
