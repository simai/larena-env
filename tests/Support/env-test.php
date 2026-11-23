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

/**
 * The panel's operations with an audit log in memory, as the application gives them: a change of the server is refused
 * without one (auditor msg-3d61bd36, point 2). Pass a sink to read what was written, a trail to follow a plan's receipt.
 */
function env_operations(Larena\Env\Contracts\PanelGateway $gateway, ?Larena\Audit\Runtime\InMemoryAuditSink $sink = null, ?Larena\Env\Operations\PlanTrail $trail = null): Larena\Env\Operations\PanelOperations
{
    $host = new Illuminate\Container\Container();
    $host->instance(Larena\Audit\Contracts\AuditSink::class, $sink ?? new Larena\Audit\Runtime\InMemoryAuditSink());

    return new Larena\Env\Operations\PanelOperations($gateway, new Larena\Env\People\PanelAudit($host), $trail);
}

/**
 * Core's access gate in a test: the person holds the operation's right when `$allows(actor, right)` says so; every right
 * by default (the screens' own tests are not about rights).
 */
final class EnvTestAccessGate implements Larena\Core\Contracts\OperationAccessGate
{
    /** @var list<array{actor: string, right: ?string}> */
    public array $asked = [];

    /** @param (Closure(string, string): bool)|null $allows */
    public function __construct(private ?Closure $allows = null)
    {
    }

    public function decideAccess(Larena\Core\Contracts\OperationDescriptor $descriptor, Larena\Core\Contracts\OperationContext $context): Larena\Core\Contracts\OperationDecision
    {
        $this->asked[] = ['actor' => $context->actorId, 'right' => $descriptor->accessScope];
        $allowed = $this->allows === null || ($this->allows)($context->actorId, (string) $descriptor->accessScope);

        return $allowed ? Larena\Core\Contracts\OperationDecision::allowed(Larena\Core\Enums\OperationExecutionMode::Sync, 'access_scope_allowed')
            : Larena\Core\Contracts\OperationDecision::denied('access_denied', 'The actor is not allowed to execute this operation.');
    }
}

/** Core's decisions and results as the runtime records them, in memory; a recorder that fails when `$fails`. */
final class EnvTestOperationAudit implements Larena\Core\Contracts\OperationAuditRecorder
{
    /** @var list<array{type: string, operation: string, phase: string, status: string}> */
    public array $events = [];

    public function __construct(public bool $fails = false)
    {
    }

    public function recordDecision(Larena\Core\Contracts\OperationDescriptor $descriptor, Larena\Core\Contracts\OperationContext $context,
        Larena\Core\Contracts\OperationDecision $decision, string $phase): array
    {
        return $this->record($descriptor, $decision->status->value, $phase);
    }

    public function recordResult(Larena\Core\Contracts\OperationDescriptor $descriptor, Larena\Core\Contracts\OperationContext $context,
        Larena\Core\Contracts\OperationResult $result, string $phase): array
    {
        return $this->record($descriptor, $result->decision->status->value, $phase);
    }

    /** @return array<string, mixed> */
    private function record(Larena\Core\Contracts\OperationDescriptor $descriptor, string $status, string $phase): array
    {
        if ($this->fails) {
            throw new RuntimeException('audit sink down');
        }
        $event = ['type' => ($descriptor->auditEvent ?? 'core.operation.read').'.'.$phase, 'operation' => $descriptor->name, 'phase' => $phase, 'status' => $status];
        $this->events[] = $event;

        return $event;
    }
}

/**
 * The screens' way to change the server, as the application composes it: the panel's operations registered in Core's
 * registry (policy clean), run by Core's registry runtime with an access gate and an audit recorder, the handlers through
 * the gateway (and DNS's service, when given). Every right is held unless the gate says otherwise.
 */
function env_facade(Larena\Env\Contracts\PanelGateway $gateway, ?Larena\Audit\Runtime\InMemoryAuditSink $sink = null, ?Larena\Env\Operations\PlanTrail $trail = null,
    ?EnvTestAccessGate $gate = null, ?EnvTestOperationAudit $audit = null, ?Larena\Env\Dns\DnsService $dns = null, ?Larena\Env\Operations\PanelOperations $panel = null): Larena\Env\Operations\EnvOperations
{
    $call = new Larena\Env\Operations\EnvOperationCall();
    $registry = Larena\Core\Registry\DeclaredOperationRegistry::fromProviders([new Larena\Env\Operations\EnvOperationProvider()]);
    $catalog = new Larena\Core\Runtime\OperationHandlerCatalog();
    $catalog->register(Larena\Env\Operations\EnvOperationCatalog::GATEWAY_HANDLER, static fn (): Larena\Env\Operations\GatewayOperationHandler
        => new Larena\Env\Operations\GatewayOperationHandler(static fn (): Larena\Env\Operations\EnvOperationCall => $call));
    $catalog->register(Larena\Env\Operations\EnvOperationCatalog::DNS_HANDLER, static fn (): Larena\Env\Operations\DnsOperationHandler
        => new Larena\Env\Operations\DnsOperationHandler(static fn (): Larena\Env\Dns\DnsService => $dns ?? throw new LogicException('no DNS in this test'),
            static fn (): Larena\Env\Operations\EnvOperationCall => $call));
    $handler = new Larena\Core\Runtime\CatalogOperationHandler($registry, $catalog);
    $runtime = new Larena\Core\Runtime\RegistryOperationRuntime($registry, new Larena\Core\Runtime\SyncOperationRuntime(
        $gate ?? new EnvTestAccessGate(),
        new Larena\Core\Runtime\UndeclaredCapabilityGate(),
        $audit ?? new EnvTestOperationAudit(),
        $handler,
    ), $handler);

    return new Larena\Env\Operations\EnvOperations($panel ?? env_operations($gateway, $sink, $trail), $runtime, $call);
}
