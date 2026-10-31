<?php

declare(strict_types=1);

// The panel's two levels at the door of every screen: who may read the server asks for the whole server, or as one of
// its people while looking at the panel as them (marked impersonated_by); anyone else asks as the owner they are tied
// to, and a person tied to none is refused with owner_unmapped, never sent as the whole server.

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Larena\Access\Runtime\AccessOperationAuthorizer;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Larena\Env\Gateway\PanelActor;
use Larena\Env\Gateway\PanelReason;
use Larena\Env\Gateway\PanelRefused;
use Larena\Env\Http\Middleware\ResolvePanelActor;
use Larena\Env\Http\Support\PanelActorResolver;
use Larena\Env\People\PersonOwners;

require_once dirname(__DIR__).'/Support/env-test.php';

$config = new class implements Repository {
    /** @var array<string, mixed> */
    private array $items = ['larena-access.operations' => ['env.server.read' => [
        'target' => 'server:host',
        'required_grants' => ['read'],
        'test_grants' => ['test:system' => ['host' => ['read']], 'test:staff' => ['host' => []], 'test:anna' => ['host' => []]],
    ]]];

    public function has($key): bool { return array_key_exists($key, $this->items); }
    public function get($key, $default = null): mixed { return $this->items[$key] ?? $default; }
    public function all(): array { return $this->items; }
    public function set($key, $value = null): void { $this->items[$key] = $value; }
    public function prepend($key, $value): void {}
    public function push($key, $value): void {}
};
// The people's owners, in a database of their own.
$capsule = new Capsule();
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$capsule->setAsGlobal();
$capsule->getConnection()->getSchemaBuilder()->create(PersonOwners::TABLE, static function ($table): void {
    $table->string('subject_ref', 191)->primary();
    $table->string('owner', 32);
    $table->string('assigned_by', 191);
    $table->timestamp('assigned_at');
});
$owners = new PersonOwners($capsule->getConnection());
$owners->assign('test:anna', 'demo', 'test:system');
env_assert($owners->ownerOf('test:anna') === 'demo' && $owners->all() === ['test:anna' => 'demo'], 'A person is tied to an owner.');
try {
    $owners->assign('test:anna', 'Not An Owner', 'test:system');
    env_assert(false, 'An owner the server cannot have is refused.');
} catch (PanelRefused $refused) {
    env_assert($refused->reasonCode === PanelReason::INVALID_ARGUMENT, 'An invalid owner name.');
}

$resolver = new PanelActorResolver(new AccessOperationAuthorizer($config, 'testing'), $owners);
$request = static function (string $actor, ?string $actingAs = null): Request {
    $request = Request::create('/admin/server/sites/demo-php.test');
    $request->attributes->set('larena_access_actor', $actor);
    $session = new Store('test', new ArraySessionHandler(10));
    if ($actingAs !== null) {
        $session->put(PanelActorResolver::ACTING_AS, $actingAs);
    }
    $request->setLaravelSession($session);

    return $request;
};
$same = static fn (PanelActor $actor, array $expected): bool => $actor->toDocument() === $expected;

// The system level reads the whole server: no owner.
env_assert($same($resolver->resolve($request('test:system')), ['user' => 'test:system', 'owner' => null, 'impersonated_by' => null]),
    'The system level asks as the whole server.');

// A person at the user level asks as their owner; the gateway narrows every answer to it.
env_assert($same($resolver->resolve($request('test:anna')), ['user' => 'test:anna', 'owner' => 'demo', 'impersonated_by' => null]),
    'A person tied to an owner asks as that owner.');

// The system level looking at the panel as a person: as them, in their space, marked as acting for them.
env_assert($same($resolver->resolve($request('test:system', 'test:anna')), ['user' => 'test:anna', 'owner' => 'demo', 'impersonated_by' => 'test:system']),
    'Acting for a person is marked impersonated_by.');
// Someone without the server's right cannot act for anyone: the session value is ignored.
env_assert($same($resolver->resolve($request('test:anna', 'test:staff')), ['user' => 'test:anna', 'owner' => 'demo', 'impersonated_by' => null]),
    'Only the system level acts for someone.');
// Acting for a person no longer tied to an owner falls back to the person's own view, and forgets it.
$stale = $request('test:system', 'test:staff');
env_assert($same($resolver->resolve($stale), ['user' => 'test:system', 'owner' => null, 'impersonated_by' => null])
    && $stale->session()->get(PanelActorResolver::ACTING_AS) === null, 'A stale acting is dropped.');

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
