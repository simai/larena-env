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

foreach (['ssl:renew', 'site:deploy', 'site:remove', 'owner:create', 'job:cancel', 'nonsense', ''] as $command) {
    env_assert(env_refusal(static fn () => new PanelRequest('r', $actor, $command)) === 'not_allowed', "Only reads may be asked here, not {$command}.");
}

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
