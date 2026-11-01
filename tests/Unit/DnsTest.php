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
foreach (glob(dirname(__DIR__, 2).'/database/migrations/*.php') ?: [] as $migration) {
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
$dns = new DnsService($provider, $connection, new DnsChangeLog($db));
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
$batch = $dns->apply($plan, 'user:admin_identity:1');
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

// Disconnecting forgets the token.
$dns->disconnect('user:admin_identity:1');
env_assert($connection->token() === null && $dns->connection() === null, 'The token is gone.');

echo "DnsTest passed\n";
