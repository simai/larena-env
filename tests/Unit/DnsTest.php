<?php

declare(strict_types=1);

// The panel's DNS (Panel plan, stage 7): Cloudflare's API through Laravel's HTTP client, faked here (no network);
// the token kept encrypted and never shown; records checked before the provider is asked; a change planned, made,
// logged with the record as it was, and put back; a site's domain read and pointed at this server.

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Facade;
use Larena\Env\Dns\CloudflareDnsProvider;
use Larena\Env\Dns\DnsChange;
use Larena\Env\Dns\DnsChangeLog;
use Larena\Env\Dns\DnsConnection;
use Larena\Env\Dns\DnsFailed;
use Larena\Env\Dns\DnsRecordInput;
use Larena\Env\Dns\DnsService;
use Larena\Env\Dns\DnsZone;
use Larena\Env\Dns\SiteDns;

require_once dirname(__DIR__).'/Support/env-test.php';

/** @param array<string, mixed> $parameters */
function route(string $name, array $parameters = [], bool $absolute = true): string
{
    return '/admin/server/'.$name.($parameters === [] ? '' : '/'.implode('/', $parameters));
}
require_once dirname(__DIR__).'/Support/AdminPresenceHost.php';

$refuses = static function (callable $call, string $reason): bool {
    try {
        $call();
    } catch (DnsFailed $failed) {
        return $failed->reason === $reason;
    }

    return false;
};

// A record is checked before the provider is asked.
$zone = new DnsZone(str_repeat('a', 32), 'example.com', 'active', ['#zone:read', '#dns_records:edit']);
$input = static fn (array $fields): DnsRecordInput => DnsRecordInput::fromForm($fields, $zone);
env_assert($input(['type' => 'A', 'name' => '@', 'content' => '192.0.2.10'])->name === 'example.com', '@ is the zone itself.');
env_assert($input(['type' => 'CNAME', 'name' => 'www', 'content' => 'example.com'])->name === 'www.example.com', 'A short name is under the zone.');
env_assert($input(['type' => 'A', 'name' => 'shop.example.com', 'content' => '192.0.2.10'])->name === 'shop.example.com', 'A full name in the zone stays.');
env_assert($refuses(static fn () => $input(['type' => 'A', 'name' => '@', 'content' => '2001:db8::1']), 'record_content_invalid'), 'An A takes IPv4 only.');
env_assert($refuses(static fn () => $input(['type' => 'AAAA', 'name' => '@', 'content' => '192.0.2.1']), 'record_content_invalid'), 'An AAAA takes IPv6 only.');
env_assert($refuses(static fn () => $input(['type' => 'MX', 'name' => '@', 'content' => 'mx.example.com']), 'record_content_invalid'), 'An MX needs its priority.');
env_assert($input(['type' => 'MX', 'name' => '@', 'content' => 'mx.example.com', 'priority' => '10'])->toPayload()['priority'] === 10, 'An MX with its priority.');
env_assert($refuses(static fn () => $input(['type' => 'NS', 'name' => '@', 'content' => 'ns.example.com']), 'record_type_invalid'), 'NS is not the panel\'s.');
env_assert($refuses(static fn () => $input(['type' => 'TXT', 'name' => '@', 'content' => "a\nb"]), 'record_content_invalid'), 'A TXT is one printable line.');
env_assert($refuses(static fn () => $input(['type' => 'A', 'name' => '@', 'content' => '192.0.2.1', 'ttl' => '30']), 'record_ttl_invalid'), 'TTL from 60.');
env_assert($refuses(static fn () => $input(['type' => 'TXT', 'name' => '@', 'content' => 'v=spf1 -all', 'proxied' => 'yes']), 'record_proxied_invalid'), 'Only addresses and aliases are proxied.');
env_assert($refuses(static fn () => $input(['type' => 'A', 'name' => 'bad name', 'content' => '192.0.2.1']), 'record_name_invalid'), 'A name with a space.');
$caa = $input(['type' => 'CAA', 'name' => '@', 'content' => '0 issue "letsencrypt.org"'])->toPayload();
env_assert($caa['data'] === ['flags' => 0, 'tag' => 'issue', 'value' => 'letsencrypt.org'], 'A CAA is written by its parts.');
$srv = $input(['type' => 'SRV', 'name' => '_sip._tcp', 'content' => '5 5060 sip.example.com', 'priority' => '10'])->toPayload();
env_assert($srv['data'] === ['priority' => 10, 'weight' => 5, 'port' => 5060, 'target' => 'sip.example.com'], 'An SRV is written by its parts.');
env_assert($refuses(static fn () => $input(['type' => 'SRV', 'name' => 'sip', 'content' => '5 5060 sip.example.com', 'priority' => '10']), 'record_content_invalid'), 'An SRV name starts with _service._proto.');

// Cloudflare, faked: the token goes only as Bearer; zones and records read page by page.
$token = 'cf-test-'.str_repeat('x', 32);
$zoneId = str_repeat('a', 32);
$state = [
    'records' => [
        ['id' => str_repeat('1', 32), 'type' => 'A', 'name' => 'example.com', 'content' => '198.51.100.7', 'ttl' => 1, 'proxied' => false],
        ['id' => str_repeat('2', 32), 'type' => 'A', 'name' => 'www.example.com', 'content' => '198.51.100.7', 'ttl' => 300, 'proxied' => false],
        ['id' => str_repeat('3', 32), 'type' => 'MX', 'name' => 'example.com', 'content' => 'mx.example.net', 'ttl' => 1, 'priority' => 10],
        ['id' => str_repeat('4', 32), 'type' => 'NS', 'name' => 'example.com', 'content' => 'ns1.cloudflare.com', 'ttl' => 1],
    ],
    'next' => 5,
];
$sent = [];
$http = new Factory();
$ok = static fn ($result, array $info = []): array => ['success' => true, 'errors' => [], 'result' => $result] + ($info === [] ? [] : ['result_info' => $info]);
$http->fake(static function (ClientRequest $request) use (&$state, &$sent, $token, $zoneId, $ok, $http) {
    $sent[] = $request->method().' '.parse_url($request->url(), PHP_URL_PATH);
    if ($request->header('Authorization') !== ['Bearer '.$token]) {
        return $http->response(['success' => false, 'errors' => [['code' => 10000, 'message' => 'Authentication error']]], 403);
    }
    $path = (string) parse_url($request->url(), PHP_URL_PATH);
    if ($path === '/client/v4/user/tokens/verify') {
        return $http->response($ok(['id' => 'abc', 'status' => 'active', 'expires_on' => '2027-01-01T00:00:00Z']));
    }
    if ($path === '/client/v4/zones') {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $page = (int) ($query['page'] ?? 1);

        return $http->response($ok($page === 1
            ? [['id' => $zoneId, 'name' => 'example.com', 'status' => 'active', 'permissions' => ['#zone:read', '#dns_records:edit']]]
            : [['id' => str_repeat('b', 32), 'name' => 'readonly.org', 'status' => 'active', 'permissions' => ['#zone:read']]],
            ['page' => $page, 'total_pages' => 2]));
    }
    if ($path === '/client/v4/zones/'.$zoneId.'/dns_records' && $request->method() === 'GET') {
        return $http->response($ok($state['records'], ['page' => 1, 'total_pages' => 1]));
    }
    if ($path === '/client/v4/zones/'.$zoneId.'/dns_records' && $request->method() === 'POST') {
        $record = $request->data() + ['id' => str_repeat((string) $state['next']++, 32)];
        $state['records'][] = $record;

        return $http->response($ok($record));
    }
    if (preg_match('#/dns_records/([a-f0-9]{32})$#', $path, $match) === 1) {
        foreach ($state['records'] as $index => $record) {
            if ($record['id'] === $match[1]) {
                if ($request->method() === 'DELETE') {
                    unset($state['records'][$index]);
                    $state['records'] = array_values($state['records']);

                    return $http->response($ok(['id' => $match[1]]));
                }
                $state['records'][$index] = $request->data() + ['id' => $match[1]];

                return $http->response($ok($state['records'][$index]));
            }
        }
    }

    return $http->response(['success' => false, 'errors' => [['code' => 7003, 'message' => 'Not found']]], 404);
});
$provider = new CloudflareDnsProvider($http);
env_assert($refuses(static fn () => $provider->verify('cf-wrong-'.str_repeat('y', 32)), 'token_refused'), 'A wrong token is refused in words.');
env_assert($refuses(static fn () => $provider->verify('short'), 'token_invalid'), 'Not a token at all, before any request.');
$zones = $provider->zones($token);
env_assert(array_map(static fn (DnsZone $zone): string => $zone->name, $zones) === ['example.com', 'readonly.org'], 'Both pages of zones.');
env_assert($zones[0]->editable() && !$zones[1]->editable(), 'What the token may do in each zone.');
$records = $provider->records($token, $zoneId);
env_assert(count($records) === 3 && !in_array('NS', array_map(static fn ($record): string => $record->type, $records), true), 'NS is not shown.');

// The connection: the token is encrypted, never kept in the clear, shown by its last four characters.
$capsule = new Capsule();
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$capsule->setAsGlobal();
$container = new AdminPresenceHost();
$container->instance('db.schema', $capsule->getConnection()->getSchemaBuilder());
Facade::setFacadeApplication($container);
foreach ([...(glob(dirname(__DIR__, 2).'/database/migrations/*.php') ?: []), ...(glob(dirname(__DIR__, 2).'/database/migrations/dns/*.php') ?: [])] as $migration) {
    (require $migration)->up();
}
$db = $capsule->getConnection();
$encrypter = new class implements Illuminate\Contracts\Encryption\StringEncrypter {
    public function encryptString(#[\SensitiveParameter] $value): string
    {
        return 'sealed:'.base64_encode(strrev((string) $value));
    }

    public function decryptString($payload): string
    {
        if (!str_starts_with((string) $payload, 'sealed:')) {
            throw new Illuminate\Contracts\Encryption\DecryptException('not sealed');
        }

        return strrev((string) base64_decode(substr((string) $payload, 7)));
    }
};
$connection = new DnsConnection($db, $encrypter);
// No change of DNS without the audit log (wave A's addition c): refused before the provider or the connection is touched.
$unaudited = new DnsService($provider, $connection, new DnsChangeLog($db));
$sentBefore = count($sent);
env_assert($refuses(static fn () => $unaudited->connect($token, 'user:admin_identity:1'), 'audit_unavailable')
    && $refuses(static fn () => $unaudited->disconnect('user:admin_identity:1'), 'audit_unavailable')
    && count($sent) === $sentBefore && $db->table(DnsConnection::TABLE)->count() === 0, 'Without the audit log nothing is connected and the provider is not asked.');
$dnsAudit = new Larena\Audit\Runtime\InMemoryAuditSink();
$dnsHost = new Illuminate\Container\Container();
$dnsHost->instance(Larena\Audit\Contracts\AuditSink::class, $dnsAudit);
$dns = new DnsService($provider, $connection, new DnsChangeLog($db), new Larena\Env\People\PanelAudit($dnsHost));
env_assert($dns->forSite('example.com')->state === 'not_connected', 'Before a connection the card says so.');
env_assert($refuses(static fn () => $dns->zones(), 'not_connected'), 'No zones without a connection.');
env_assert(count($dns->connect($token, 'user:admin_identity:1')) === 2, 'Connecting checks the token and reads its zones.');
$row = (array) $db->table(DnsConnection::TABLE)->first();
env_assert(!str_contains(json_encode($row, JSON_THROW_ON_ERROR), $token) && $row['token_tail'] === substr($token, -4), 'The token is kept sealed; only its last four characters are plain.');
env_assert($connection->token() === $token && $dns->connection()?->tokenStatus === 'active', 'The token opens for the provider\'s calls.');

// The site card: where the domain points. Without the server's address the panel cannot tell.
env_assert($dns->forSite('example.com')->points === SiteDns::UNKNOWN, 'Without the server\'s address: cannot tell.');
env_assert($refuses(static fn () => $dns->setServerAddresses('not-an-ip', null), 'address_invalid'), 'An address is checked.');
$dns->setServerAddresses('203.0.113.5', null);
$site = $dns->forSite('example.com');
env_assert($site->state === 'ok' && $site->zone?->name === 'example.com' && $site->points === SiteDns::ELSEWHERE, 'example.com points elsewhere.');
env_assert(count($site->records) === 3, 'The domain\'s and its www\'s records: '.count($site->records));
env_assert($dns->forSite('other.net')->state === 'zone_missing', 'A domain in none of the zones.');
env_assert($dns->forSite('shop.example.com')->points === SiteDns::NONE, 'A subdomain with no record of its own.');

// Pointing the domain here: its A changed, www as an alias of the domain; nothing done before confirmation.
$plan = $dns->planPointHere('example.com');
$lines = array_map(static fn (DnsChange $change): string => $change->action.':'.($change->after?->line() ?? $change->before?->line()), $plan);
env_assert($lines === ['update:A example.com → 203.0.113.5', 'delete:A www.example.com → 198.51.100.7', 'create:CNAME www.example.com → example.com'], implode(' | ', $lines));
env_assert($provider->records($token, $zoneId)[0]->content === '198.51.100.7', 'A plan changes nothing.');
$sentBefore = count($sent);
env_assert($refuses(static fn () => $unaudited->apply($plan, 'user:admin_identity:1'), 'audit_unavailable') && count($sent) === $sentBefore,
    'Without the audit log a confirmed change is refused before the provider is asked.');
$batch = $dns->apply($plan, 'user:admin_identity:1', null, 'plan-receipt-1', 'user:admin_identity:5');
// The chain of the change: who, for whom, the plan it was confirmed with and the batch, correlated by the plan.
$changed = array_values(array_filter($dnsAudit->events(), static fn ($event): bool => $event->type === 'env.dns.record_changed'));
env_assert(count($changed) === 3 && $changed[0]->correlationId === 'plan-receipt-1' && $changed[0]->actor === 'user:admin_identity:1'
    && $changed[0]->payload['who'] === 'user:admin_identity:1' && $changed[0]->payload['on_behalf_of'] === 'user:admin_identity:5'
    && $changed[0]->payload['plan_id'] === 'plan-receipt-1' && $changed[0]->payload['batch'] === $batch && !isset($changed[0]->payload['undo_of']),
    'Each change is in the audit log with its chain: '.json_encode($changed[0]->payload ?? null));
env_assert($dns->forSite('example.com')->points === SiteDns::HERE, 'Now it points here.');
env_assert(!in_array(true, array_map(static fn (string $line): bool => str_contains($line, $token), $sent), true), 'The token never goes in a path.');
env_assert(count($sent) > $sentBefore, 'The provider was asked.');
env_assert($refuses(static fn () => $dns->planPointHere('example.com'), 'nothing_to_change'), 'Pointed already: nothing to plan.');
$logged = $db->table(DnsChangeLog::TABLE)->where('batch', $batch)->count();
env_assert($logged === 3, 'Each change is logged with the record as it was.');

// Putting the last change back: the alias removed, the www address made again, the A changed back.
$found = $dns->zone($zoneId);
$undo = $dns->planUndo($found);
env_assert(array_map(static fn (DnsChange $change): string => $change->action, $undo) === ['delete', 'create', 'update'], 'In reverse order.');
$dns->apply($undo, 'user:admin_identity:1', $batch);
env_assert($dns->forSite('example.com')->points === SiteDns::ELSEWHERE, 'Back as it was.');
env_assert($db->table(DnsChangeLog::TABLE)->where('batch', $batch)->whereNotNull('undone_at')->count() === 3, 'The batch is marked put back.');

// One record by hand: a change that changes nothing is not planned; a record changed meanwhile stops the plan.
$mx = null;
foreach ($dns->records($found) as $record) {
    $mx = $record->type === 'MX' ? $record : $mx;
}
env_assert($refuses(static fn () => $dns->planRecord($found, DnsChange::UPDATE, $mx->id, ['name' => '@', 'content' => 'mx.example.net', 'priority' => '10', 'ttl' => '1']), 'nothing_to_change'), 'Nothing to change.');
$change = $dns->planRecord($found, DnsChange::UPDATE, $mx->id, ['name' => '@', 'content' => 'mx2.example.net', 'priority' => '20', 'ttl' => '1']);
foreach ($state['records'] as $index => $record) {
    if ($record['id'] === $mx->id) {
        $state['records'][$index]['content'] = 'changed.example.net';
    }
}
env_assert($refuses(static fn () => $dns->apply($change, 'user:admin_identity:1'), 'changed_meanwhile'), 'A record changed since the plan stops it.');
$readonly = $dns->zone(str_repeat('b', 32));
env_assert($refuses(static fn () => $dns->planRecord($readonly, DnsChange::CREATE, null, ['type' => 'TXT', 'name' => '@', 'content' => 'x']), 'zone_read_only'), 'A zone the token only reads.');
$txt = $dns->planRecord($found, DnsChange::CREATE, null, ['type' => 'TXT', 'name' => '@', 'content' => 'v=spf1 -all']);
$dns->apply($txt, 'user:admin_identity:1');
env_assert($refuses(static fn () => $dns->planRecord($found, DnsChange::CREATE, null, ['type' => 'TXT', 'name' => '@', 'content' => 'v=spf1 -all']), 'nothing_to_change'), 'The same record twice.');
// A plan survives the session as plain data.
env_assert(DnsChange::fromArray($txt[0]->toArray())->after?->line() === $txt[0]->after?->line(), 'A planned change round-trips.');

// Certificates through DNS (simai-env 1.36.0): the connection's token is handed to the server, planned and confirmed;
// the page never carries it. Without a Cloudflare connection, or with a token the server would not take, nothing is asked.
$translator = new class implements Illuminate\Contracts\Translation\Translator {
    public function get($key, array $replace = [], $locale = null)
    {
        return $key;
    }

    public function choice($key, $number, array $replace = [], $locale = null)
    {
        return $key;
    }

    public function getLocale()
    {
        return 'en';
    }

    public function setLocale($locale): void
    {
    }
};
$files = new Illuminate\Filesystem\Filesystem();
$engines = new Illuminate\View\Engines\EngineResolver();
$engines->register('blade', static fn () => new Illuminate\View\Engines\FileEngine($files));
$views = new Illuminate\View\Factory($engines, new Illuminate\View\FileViewFinder($files, []), new Illuminate\Events\Dispatcher());
$views->addExtension('blade.php', 'blade');
$views->addNamespace('larena-env', dirname(__DIR__, 2).'/resources/views');
$clock = 1_800_000_000;
$certificatesGateway = new class (new Larena\Env\Gateway\RecordedAnswersGateway(env_recorded_path())) implements Larena\Env\Contracts\PanelGateway {
    public int $started = 0;
    public function __construct(private Larena\Env\Contracts\PanelGateway $recorded) {}
    public function ask(Larena\Env\Gateway\PanelRequest $request): Larena\Env\Gateway\PanelAnswer
    {
        $this->started += $request->confirm ? 1 : 0;

        return $this->recorded->ask($request);
    }
    public function source(): string { return 'recorded'; }
};
$certificates = new Larena\Env\Http\Controllers\DnsCertificatesController(
    env_operations($certificatesGateway), $connection, $views, $translator, env_plan_receipts($clock));
$certificatesSession = new Illuminate\Session\Store('panel', new Illuminate\Session\ArraySessionHandler(10));
$asked = static function (string $op, string $as = 'admin@stand.test', ?string $receipt = null) use ($certificatesSession): Illuminate\Http\Request {
    $request = Illuminate\Http\Request::create('/admin/server/dns/certificates/plan', 'POST', ['op' => $op] + ($receipt !== null ? ['receipt' => $receipt] : []));
    $request->attributes->set(Larena\Env\Http\Middleware\ResolvePanelActor::ATTRIBUTE, new Larena\Env\Gateway\PanelActor($as));
    $request->setLaravelSession($certificatesSession);

    return $request;
};
$stored = $certificates->plan($asked('store'));
$storedReceipt = (string) ($stored->getData()['hidden']['receipt'] ?? '');
env_assert($stored instanceof Illuminate\View\View && $stored->getData()['plan']->command === 'ssl:dns-credentials'
    && array_diff_key($stored->getData()['hidden'], ['receipt' => true]) === ['op' => 'store'] && strlen($storedReceipt) === 32
    && !str_contains(json_encode($stored->getData()['hidden'], JSON_THROW_ON_ERROR), $token), 'Giving the token is planned; the page carries the step and its receipt, not the token.');
env_assert($certificates->plan($asked('remove')) instanceof Illuminate\View\View, 'Taking it back is planned too.');
// The confirmation carries the plan's receipt: without it, for the other step, again, or after 15 minutes, nothing is sent.
$noReceipt = $certificates->run($asked('store'));
env_assert($certificatesGateway->started === 0 && is_string($noReceipt->getSession()?->get('larena_env_notice')) && str_ends_with($noReceipt->getTargetUrl(), '#env-dns-certificates'),
    'Without the receipt: refused in words, back to the connection, nothing sent.');
$certificates->run($asked('remove', 'admin@stand.test', $storedReceipt));
env_assert($certificatesGateway->started === 0, 'The receipt of giving the token does not take it back.');
$stored = $certificates->plan($asked('store'));
$clock += 901;
$certificates->run($asked('store', 'admin@stand.test', (string) $stored->getData()['hidden']['receipt']));
env_assert($certificatesGateway->started === 0, 'A receipt older than 15 minutes is refused.');
$stored = $certificates->plan($asked('store'));
$storedReceipt = (string) $stored->getData()['hidden']['receipt'];
$certificates->run($asked('store', 'other@stand.test', $storedReceipt));
env_assert($certificatesGateway->started === 0, 'Another person\'s confirmation is refused.');
$stored = $certificates->plan($asked('store'));
$storedReceipt = (string) $stored->getData()['hidden']['receipt'];
$run = $certificates->run($asked('store', 'admin@stand.test', $storedReceipt));
env_assert($certificatesGateway->started === 1 && $run->getTargetUrl() !== '' && str_contains($run->getTargetUrl(), 'larena.env.admin.job'), 'Confirmed, a job: '.$run->getTargetUrl());
$certificates->run($asked('store', 'admin@stand.test', $storedReceipt));
env_assert($certificatesGateway->started === 1, 'A receipt confirms once.');
$wrong = $certificates->plan($asked('other'));
env_assert($wrong instanceof Illuminate\Http\RedirectResponse && str_ends_with($wrong->getTargetUrl(), '#env-dns-certificates'), 'Anything else goes back to the connection.');

// A DNS change by hand waits in its plan's receipt (PlanReceipts): confirmed once, by the person it was planned for,
// within 15 minutes; the form sends only the receipt's id.
$dnsClock = time();
$dnsScreen = new Larena\Env\Http\Controllers\DnsController($dns, new Larena\Env\Server\SiteDetailsReader(new Larena\Env\Gateway\RecordedAnswersGateway(env_recorded_path())),
    $views, $translator, receipts: env_plan_receipts($dnsClock));
$dnsSession = new Illuminate\Session\Store('panel', new Illuminate\Session\ArraySessionHandler(10));
$dnsAsked = static function (array $input, string $as = 'admin@stand.test') use ($dnsSession): Illuminate\Http\Request {
    $request = Illuminate\Http\Request::create('/admin/server/dns', 'POST', $input);
    $request->attributes->set(Larena\Env\Http\Middleware\ResolvePanelActor::ATTRIBUTE, new Larena\Env\Gateway\PanelActor($as));
    $request->setLaravelSession($dnsSession);

    return $request;
};
$txtCount = static fn (string $content): int => count(array_filter($dns->records($dns->zone($zoneId)), static fn ($record): bool => $record->content === $content));
$dnsPlanned = static function (string $content) use ($dnsScreen, $dnsAsked, $zoneId): string {
    $view = $dnsScreen->plan($dnsAsked(['action' => DnsChange::CREATE, 'type' => 'TXT', 'name' => '@', 'content' => $content]), $zoneId);
    env_assert($view instanceof Illuminate\View\View && strlen((string) $view->getData()['planId']) === 32, 'The change is planned with its receipt.');

    return (string) $view->getData()['planId'];
};
$late = $dnsPlanned('receipt-late');
$dnsClock += 901;
$dnsScreen->run($dnsAsked(['plan' => $late]));
env_assert($txtCount('receipt-late') === 0, 'A plan older than 15 minutes is not made.');
$other = $dnsPlanned('receipt-other');
$dnsScreen->run($dnsAsked(['plan' => $other], 'staff@stand.test'));
env_assert($txtCount('receipt-other') === 0, 'Another person\'s confirmation is refused.');
$given = $dnsPlanned('receipt-made');
$dnsScreen->run($dnsAsked(['plan' => $given]));
env_assert($txtCount('receipt-made') === 1, 'The confirmed plan is made.');
$dnsScreen->run($dnsAsked(['plan' => $given]));
env_assert($txtCount('receipt-made') === 1, 'A plan is made once.');
$dnsScreen->run($dnsAsked([]));
env_assert($dnsSession->get('larena_env_notice') === 'larena-env::admin.dns.reasons.unknown', 'Without a receipt: refused, nothing made.');

// Disconnecting forgets the token.
$dns->disconnect('user:admin_identity:1');
env_assert($connection->token() === null && $dns->connection() === null, 'The token is gone.');
env_assert(str_ends_with($certificates->plan($asked('store'))->getTargetUrl(), '#env-dns-certificates') && $certificates->plan($asked('remove')) instanceof Illuminate\View\View,
    'Without a connection nothing is given; the server\'s copy can still be taken back.');

// The alert bar's «Отметить, что видели» is one click (owner 2026-11-06): a JSON post acknowledges at once and answers
// when the server has recorded it; the plain post and the link still show the plan.
$recorded = new Larena\Env\Gateway\RecordedAnswersGateway(env_recorded_path());
$alertsController = new Larena\Env\Http\Controllers\AlertsController(env_operations($recorded),
    new Larena\Env\Server\ServerOverviewReader($recorded), new Larena\Env\Server\SiteExtrasReader($recorded), $views, $translator, $encrypter,
    $ackCache = new Illuminate\Cache\Repository(new Illuminate\Cache\ArrayStore()));
$ack = static function (string $method, bool $json): Illuminate\Http\Request {
    $request = Illuminate\Http\Request::create('/admin/server/alerts/ack/plan?id=3591eb397633&back=%2Fadmin%2Fserver', $method);
    if ($json) {
        $request->headers->set('Accept', 'application/json');
    }
    $request->attributes->set(Larena\Env\Http\Middleware\ResolvePanelActor::ATTRIBUTE, new Larena\Env\Gateway\PanelActor('admin@stand.test'));

    return $request;
};
$oneClick = $alertsController->ackPlan($ack('POST', true));
env_assert($oneClick instanceof Illuminate\Http\JsonResponse && $oneClick->getData(true)['acknowledged'] === true, 'One click acknowledges: '.json_encode($oneClick instanceof Illuminate\Http\JsonResponse ? $oneClick->getData(true) : null));
// The bar keeps its list a minute: an acknowledgement makes the next page read it anew.
env_assert($ackCache->get(Larena\Env\Warnings\EnvAdminAlertSource::GENERATION) === 1, 'The acknowledgement resets the kept alert list.');
env_assert($alertsController->ackPlan($ack('GET', true)) instanceof Illuminate\View\View && $alertsController->ackPlan($ack('POST', false)) instanceof Illuminate\View\View,
    'The link and the plain form still show the plan.');

// «Что слать» alone (simai-env 1.63.0): the same channel without an address changes only what the server sends, it keeps
// its own address and token; another kind without an address is refused; an older server is never sent `levels`.
$newer = new class ($recorded) implements Larena\Env\Contracts\PanelGateway {
    /** @var list<Larena\Env\Gateway\PanelRequest> */
    public array $asked = [];
    public string $version = '1.63.0';
    public function __construct(private Larena\Env\Gateway\RecordedAnswersGateway $inner) {}
    public function ask(Larena\Env\Gateway\PanelRequest $request): Larena\Env\Gateway\PanelAnswer
    {
        $this->asked[] = $request;
        $answer = $this->inner->ask($request);
        $data = $answer->data;
        if ($request->command === 'self:describe') {
            $data['simai_env']['version'] = $this->version;
        } elseif ($request->command === 'self:alerts') {
            $data['channel'] = ['kind' => 'telegram', 'target_hint' => 'chat …4567', 'levels' => 'urgent', 'last_sent_at' => null, 'last_error' => null];
        } else {
            return $answer;
        }

        return Larena\Env\Gateway\PanelAnswer::fromDocument($request->requestId, ['schema' => 'simai-panel-answer/1', 'request_id' => $request->requestId,
            'status' => 'ok', 'kind' => 'result', 'data' => $data]);
    }
    public function source(): string { return 'recorded'; }
    /** How many times a command was started (confirmed) since the list was last emptied. */
    public function started(string $command): int
    {
        return count(array_filter($this->asked, static fn (Larena\Env\Gateway\PanelRequest $request): bool => $request->command === $command && $request->confirm));
    }
};
$levelsController = new Larena\Env\Http\Controllers\AlertsController(env_operations($newer),
    new Larena\Env\Server\ServerOverviewReader($newer), new Larena\Env\Server\SiteExtrasReader($newer), $views, $translator, $encrypter);
$channelAsked = static function (array $input): Illuminate\Http\Request {
    $request = Illuminate\Http\Request::create('/admin/server/alerts/channel/plan', 'POST', $input);
    $request->attributes->set(Larena\Env\Http\Middleware\ResolvePanelActor::ATTRIBUTE, new Larena\Env\Gateway\PanelActor('admin@stand.test'));
    $request->setLaravelSession(new Illuminate\Session\Store('panel', new Illuminate\Session\ArraySessionHandler(10)));

    return $request;
};
$planned = static fn (): ?Larena\Env\Gateway\PanelRequest => array_values(array_filter($newer->asked,
    static fn (Larena\Env\Gateway\PanelRequest $request): bool => $request->command === 'self:alerts-channel'))[0] ?? null;
$onlyLevels = $levelsController->channelPlan($channelAsked(['kind' => 'telegram', 'target' => '', 'levels' => 'all']));
env_assert($onlyLevels instanceof Illuminate\View\View && $planned()?->args === ['kind' => 'telegram', 'levels' => 'all']
    && array_diff_key($onlyLevels->getData()['hidden'], ['receipt' => true]) === ['kind' => 'telegram', 'levels' => 'all'], 'Only «Что слать»: the current kind, no address, the levels: '.json_encode($planned()?->args));
$newer->asked = [];
$otherKind = $levelsController->channelPlan($channelAsked(['kind' => 'webhook', 'target' => '', 'levels' => 'all']));
env_assert($otherKind instanceof Illuminate\Http\RedirectResponse && $planned() === null, 'Another kind needs its address.');
$newer->version = '1.62.2';
$older = $levelsController->channelPlan($channelAsked(['kind' => 'telegram', 'target' => '', 'levels' => 'all']));
env_assert($older instanceof Illuminate\Http\RedirectResponse && $planned() === null, 'An older server: no levels, so an address is needed.');
$newer->asked = [];
$levelsController->channelPlan($channelAsked(['kind' => 'telegram', 'target' => '-1001234567', 'levels' => 'all']));
$olderAsked = $planned();
env_assert($olderAsked !== null && !array_key_exists('levels', $olderAsked->args), 'An older server is never sent levels.');

// The alerts' changes go by their plan's receipt too: the channel, the test message and a single acknowledgement.
$newer->version = '1.63.0';
$alertsClock = 1_800_000_000;
$receiptAlerts = new Larena\Env\Http\Controllers\AlertsController(env_operations($newer),
    new Larena\Env\Server\ServerOverviewReader($newer), new Larena\Env\Server\SiteExtrasReader($newer), $views, $translator, $encrypter, null, env_plan_receipts($alertsClock));
$alertsSession = new Illuminate\Session\Store('panel', new Illuminate\Session\ArraySessionHandler(10));
$alertsAsked = static function (array $input) use ($alertsSession): Illuminate\Http\Request {
    $request = Illuminate\Http\Request::create('/admin/server/alerts', 'POST', $input);
    $request->attributes->set(Larena\Env\Http\Middleware\ResolvePanelActor::ATTRIBUTE, new Larena\Env\Gateway\PanelActor('admin@stand.test'));
    $request->setLaravelSession($alertsSession);

    return $request;
};
$alertsStarted = static fn (string $command): int => $newer->started($command);
foreach ([
    'channel' => [static fn (array $in) => $receiptAlerts->channelPlan($alertsAsked($in)), static fn (array $in) => $receiptAlerts->channelRun($alertsAsked($in)),
        ['kind' => 'webhook', 'target' => 'https://hooks.example.org/panel', 'levels' => 'all'], 'self:alerts-channel', ['target' => 'https://evil.example.org/x']],
    'test' => [static fn (array $in) => $receiptAlerts->testPlan($alertsAsked($in)), static fn (array $in) => $receiptAlerts->testRun($alertsAsked($in)),
        [], 'self:alerts-test', null],
    'ack' => [static fn (array $in) => $receiptAlerts->ackPlan($alertsAsked($in)), static fn (array $in) => $receiptAlerts->ackRun($alertsAsked($in)),
        ['id' => '3591eb397633', 'back' => '/admin/server'], 'self:alerts-ack', ['id' => '3591eb397634']],
] as $name => [$plan, $run, $input, $command, $changed]) {
    $newer->asked = [];
    $hidden = static function () use ($plan, $input): array {
        $view = $plan($input);
        env_assert($view instanceof Illuminate\View\View && strlen((string) ($view->getData()['hidden']['receipt'] ?? '')) === 32, 'Planned with a receipt.');

        return $view->getData()['hidden'];
    };
    $first = $hidden();
    $run(array_diff_key($first, ['receipt' => true]));
    env_assert($alertsStarted($command) === 0, "{$name}: without the receipt nothing is sent.");
    if ($changed !== null) {
        $run($changed + $first);
        env_assert($alertsStarted($command) === 0, "{$name}: a value changed after the plan is refused.");
    }
    $late = $hidden();
    $alertsClock += 901;
    $run($late);
    env_assert($alertsStarted($command) === 0, "{$name}: an old receipt is refused.");
    $given = $hidden();
    $done = $run($given);
    env_assert($alertsStarted($command) === 1 && str_contains($done->getTargetUrl(), 'larena.env.admin.job'), "{$name}: the receipt of the plan runs it: ".$done->getTargetUrl());
    $run($given);
    env_assert($alertsStarted($command) === 1, "{$name}: a receipt runs once.");
}

echo "DnsTest passed\n";
