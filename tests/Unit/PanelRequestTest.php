<?php

declare(strict_types=1);

// A request is the protocol's or it does not exist: each refusal has the gateway's own code.

use Larena\Env\Gateway\PanelActor;
use Larena\Env\Gateway\PanelRefused;
use Larena\Env\Gateway\PanelRequest;

require_once dirname(__DIR__).'/Support/env-test.php';

function env_refusal(callable $make): ?string
{
    try {
        $make();
    } catch (PanelRefused $refused) {
        return $refused->reasonCode;
    }

    return null;
}

$actor = new PanelActor('admin@example.com');

$request = new PanelRequest('r1', $actor, 'site:describe', ['domain' => 'example.com']);
$line = $request->toLine();
env_assert(str_ends_with($line, "\n") && substr_count($line, "\n") === 1, 'A request is one line.');
env_assert(json_decode($line, true) === [
    'schema' => 'simai-panel-request/1',
    'request_id' => 'r1',
    'actor' => ['user' => 'admin@example.com', 'owner' => null, 'impersonated_by' => null],
    'command' => 'site:describe',
    'args' => ['domain' => 'example.com'],
    'confirm' => false,
], 'The request has the protocol\'s fields: '.$line);

$empty = (new PanelRequest('r2', $actor, 'self:describe'))->toLine();
env_assert(str_contains($empty, '"args":{}'), 'Empty args stay an object, the gateway refuses a list: '.$empty);

env_assert(preg_match('/\Apanel-[a-f0-9]{24}\z/', PanelRequest::read($actor, 'self:describe')->requestId) === 1, 'A generated id fits the gateway\'s pattern.');
env_assert(PanelRequest::read($actor, 'self:describe')->requestId !== PanelRequest::read($actor, 'self:describe')->requestId, 'Each request has its own id.');

// Deploys, removals, other owners' keys and anything else are outside this package's table, whatever is sent.
foreach (['site:deploy', 'site:remove', 'owner:remove', 'owner:add-key', 'self:bootstrap', 'nonsense', ''] as $command) {
    env_assert(env_refusal(static fn () => new PanelRequest('r', $actor, $command, ['domain' => 'example.com'])) === 'not_allowed', "This package may not ask for {$command}.");
}
// The actions and job commands of the screens are allowed, and only an action is ever confirmed.
env_assert(env_refusal(static fn () => new PanelRequest('r', $actor, 'ssl:renew', ['domain' => 'example.com'])) === null, 'An action may be planned.');
env_assert(env_refusal(static fn () => new PanelRequest('r', $actor, 'ssl:renew', ['domain' => 'example.com'], true)) === null, 'And confirmed.');
env_assert(env_refusal(static fn () => new PanelRequest('r', $actor, 'job:cancel', ['job_id' => str_repeat('a', 24)])) === null, 'A job may be followed.');
env_assert(env_refusal(static fn () => new PanelRequest('r', $actor, 'site:describe', ['domain' => 'example.com'], true)) === 'not_allowed', 'A read is never confirmed.');
env_assert(env_refusal(static fn () => new PanelRequest('r', $actor, 'job:cancel', ['job_id' => str_repeat('a', 24)], true)) === 'not_allowed', 'A job command is never confirmed.');
env_assert(env_refusal(static fn () => new PanelRequest('r', $actor, 'job:status', ['job_id' => 'NOTHEX0000000000000000'])) === 'invalid_argument', 'A job id is checked.');
env_assert(env_refusal(static fn () => new PanelRequest('r', $actor, 'ssl:letsencrypt', ['domain' => 'example.com'])) === 'invalid_argument', 'The certificate needs an email.');
env_assert(env_refusal(static fn () => new PanelRequest('r', $actor, 'logs:nginx', ['domain' => 'example.com', 'lines' => '5000'])) === 'invalid_argument', 'At most a thousand lines.');
// A new site, its PHP and a new owner: the gateway's own argument rules.
env_assert(env_refusal(static fn () => new PanelRequest('r', $actor, 'site:add', ['domain' => 'new.test', 'profile' => 'laravel', 'php' => '8.3', 'create-db' => 'yes', 'db-engine' => 'pgsql'])) === null, 'A new site.');
env_assert(env_refusal(static fn () => new PanelRequest('r', $actor, 'site:add', ['domain' => 'new.test', 'profile' => 'redirect', 'redirect-to' => 'javascript:alert(1)'])) === 'invalid_argument', 'A redirect goes to an http address only.');
env_assert(env_refusal(static fn () => new PanelRequest('r', $actor, 'site:add', ['domain' => 'new.test', 'owner' => '../root'])) === 'invalid_argument', 'An owner is a name.');
env_assert(env_refusal(static fn () => new PanelRequest('r', $actor, 'site:set-php', ['domain' => 'new.test', 'php' => '8.3; id'])) === 'invalid_argument', 'A PHP version is a version.');
env_assert(env_refusal(static fn () => new PanelRequest('r', $actor, 'owner:create', ['name' => 'acme', 'pubkey' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl dev@acme'])) === null, 'A new owner with a key.');
env_assert(env_refusal(static fn () => new PanelRequest('r', $actor, 'owner:create', ['name' => 'acme', 'pubkey' => "ssh-ed25519 AAAA\nsecond line"])) === 'invalid_argument', 'A key is one line.');

foreach (['a;rm -rf /', '$(id).test', '`id`.test', '-oProxyCommand=x', "ok.test\n", 'nodot', "ok.test\0", 'a b.test', '../etc.test'] as $domain) {
    env_assert(env_refusal(static fn () => new PanelRequest('r', $actor, 'site:describe', ['domain' => $domain])) === 'invalid_argument', 'A hostile domain is refused: '.addcslashes($domain, "\n\0"));
}
env_assert(env_refusal(static fn () => new PanelRequest('r', $actor, 'site:describe')) === 'invalid_argument', 'A required argument is missing.');
env_assert(env_refusal(static fn () => new PanelRequest('r', $actor, 'self:describe', ['domain' => 'example.com'])) === 'invalid_argument', 'An unknown option is refused.');
env_assert(env_refusal(static fn () => new PanelRequest('r', $actor, 'site:doctor', ['domain' => 'example.com', 'strict' => 'maybe'])) === 'invalid_argument', 'A yes/no option is checked.');

foreach (['', "two\nlines", "tab\t", str_repeat('a', 255), "bad\xFF", "wide\u{2028}", "nbsp\u{00A0}"] as $user) {
    env_assert(env_refusal(static fn () => new PanelActor($user)) === 'bad_actor', 'A user the gateway refuses is refused here: '.bin2hex($user));
}
foreach (['Upper', '1digit', 'with space', str_repeat('a', 33)] as $owner) {
    env_assert(env_refusal(static fn () => new PanelActor('a@b.c', $owner)) === 'bad_actor', "An invalid owner: {$owner}.");
}
env_assert(env_refusal(static fn () => new PanelActor('Рим Забаров')) === null, 'A printable name with a space is a user.');

foreach (['', 'with space', str_repeat('a', 65), "id\n"] as $id) {
    env_assert(env_refusal(static fn () => new PanelRequest($id, $actor, 'self:describe')) === 'bad_request', 'An invalid request id is refused.');
}

echo "PanelRequestTest passed.\n";
