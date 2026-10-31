<?php

declare(strict_types=1);

// The panel's two levels at the door of every screen: who may read the server asks for the whole server; anyone
// else is not yet tied to an owner (stage 2) and is refused with owner_unmapped, never sent as the whole server.

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Larena\Access\Runtime\AccessOperationAuthorizer;
use Larena\Env\Gateway\PanelReason;
use Larena\Env\Gateway\PanelRefused;
use Larena\Env\Http\Middleware\ResolvePanelActor;
use Larena\Env\Http\Support\PanelActorResolver;

require_once dirname(__DIR__).'/Support/env-test.php';

$config = new class implements Repository {
    /** @var array<string, mixed> */
    private array $items = ['larena-access.operations' => ['env.server.read' => [
        'target' => 'server:host',
        'required_grants' => ['read'],
        'test_grants' => ['test:system' => ['host' => ['read']], 'test:staff' => ['host' => []]],
    ]]];

    public function has($key): bool { return array_key_exists($key, $this->items); }
    public function get($key, $default = null): mixed { return $this->items[$key] ?? $default; }
    public function all(): array { return $this->items; }
    public function set($key, $value = null): void { $this->items[$key] = $value; }
    public function prepend($key, $value): void {}
    public function push($key, $value): void {}
};
$resolver = new PanelActorResolver(new AccessOperationAuthorizer($config, 'testing'));
$request = static function (string $actor): Request {
    $request = Request::create('/admin/server/sites/demo-php.test');
    $request->attributes->set('larena_access_actor', $actor);

    return $request;
};

// The system level reads the whole server: no owner.
env_assert($resolver->resolve($request('test:system')) === ['test:system', null], 'The system level asks as the whole server.');

// A person without the server's right and without an owner is refused, never sent as the whole server.
$refused = null;
try {
    $resolver->resolve($request('test:staff'));
} catch (PanelRefused $exception) {
    $refused = $exception->reasonCode;
}
env_assert($refused === PanelReason::OWNER_UNMAPPED, 'A person not tied to an owner is refused with owner_unmapped.');

// The screen answers 403 in the person's words, and nothing goes on to the gateway.
$lines = require dirname(__DIR__, 2).'/resources/lang/en/admin.php';
$translator = new class ($lines) implements Translator {
    /** @param array<string, mixed> $lines */
    public function __construct(private array $lines) {}
    public function get($key, array $replace = [], $locale = null): mixed
    {
        return data_get($this->lines, str_replace('larena-env::admin.', '', $key), $key);
    }
    public function choice($key, $number, array $replace = [], $locale = null): string { return (string) $this->get($key); }
    public function getLocale(): string { return 'en'; }
    public function setLocale($locale): void {}
};
$middleware = new ResolvePanelActor($resolver, $translator);
$reached = false;
$answer = $middleware->handle($request('test:staff'), static function () use (&$reached): Response {
    $reached = true;

    return new Response('screen');
});
env_assert($answer->getStatusCode() === 403 && !$reached, 'The screen is not reached.');
env_assert($answer->getContent() === $lines['reasons']['owner_unmapped'], 'The refusal says why in words.');

// A request without the resolved actor never falls back to the whole server.
$missing = null;
try {
    ResolvePanelActor::of(Request::create('/admin/server'));
} catch (PanelRefused $exception) {
    $missing = $exception->reasonCode;
}
env_assert($missing === PanelReason::OWNER_UNMAPPED, 'No resolved actor is no actor.');

echo "PanelActorResolverTest passed.\n";
