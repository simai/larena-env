<?php

declare(strict_types=1);

namespace Larena\Env\Operations;

use Closure;
use Larena\Core\Contracts\OperationContext;
use Larena\Core\Contracts\OperationDescriptor;
use Larena\Core\Contracts\OperationProposalHandler;
use Larena\Env\Dns\DnsChange;
use Larena\Env\Dns\DnsFailed;
use Larena\Env\Dns\DnsService;
use LogicException;
use Throwable;

/**
 * DNS through the provider's API as Core operations (plan §2, three operations over DnsService, and a site's own domain
 * pointed here). The plan of a change is made on the screen before (DnsService::planRecord, planPointHere, planUndo)
 * and comes back as the approved input; the proposal says what would change and changes nothing. DnsService keeps its
 * own rules: no audit log, no change, and every record changed is written as `env.dns.record_changed`. A refusal of the
 * provider (DnsFailed) is left for the screen to say in words.
 */
final readonly class DnsOperationHandler implements OperationProposalHandler
{
    /**
     * @param Closure(): DnsService $dns
     * @param Closure(): EnvOperationCall $call
     */
    public function __construct(private Closure $dns, private Closure $call)
    {
    }

    /** @return array<string, mixed> */
    public function propose(OperationDescriptor $descriptor, OperationContext $context): array
    {
        $changes = $context->metadata['changes'] ?? [];

        return ['kind' => 'dns', 'operation' => $descriptor->name, 'reversible' => EnvOperationCatalog::reversibility($descriptor->name),
            'changes' => is_array($changes) ? count($changes) : 0];
    }

    /** @return array<string, mixed> */
    public function handle(OperationDescriptor $descriptor, OperationContext $context): array
    {
        $call = ($this->call)();
        $actor = $call->actor ?? throw new LogicException('env_operation_call_missing');
        $who = $actor->impersonatedBy ?? $actor->user;
        $input = $context->metadata;
        try {
            $answer = match ($descriptor->name) {
                'env.dns.connect' => ['zones' => count(($this->dns)()->connect(is_string($input['token'] ?? null) ? $input['token'] : '', $who))],
                'env.dns.disconnect' => (function () use ($who): array {
                    ($this->dns)()->disconnect($who);

                    return ['disconnected' => true];
                })(),
                'env.dns.records_apply', 'env.site.dns_point' => self::apply(($this->dns)(), $descriptor->name, $input, $who,
                    $actor->impersonatedBy !== null ? $actor->user : null),
                default => throw new LogicException('env_operation_not_dns'),
            };
        } catch (DnsFailed $failed) {
            // The provider's or the panel's refusal: the screen says it; Core sees the handler fail safely.
            $call->failure = $failed;
            throw $failed;
        } catch (Throwable $failure) {
            $call->failure = $failure;
            throw $failure;
        }
        $call->result = $answer;

        return $answer;
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{batch: string, changes: int}
     */
    private static function apply(DnsService $dns, string $operation, array $input, string $who, ?string $onBehalfOf): array
    {
        $changes = array_map(static fn (mixed $change): DnsChange => DnsChange::fromArray(is_array($change) ? $change : []),
            array_values(is_array($input['changes'] ?? null) ? $input['changes'] : []));
        $batch = $dns->apply($changes, $who, is_string($input['undo_of'] ?? null) ? $input['undo_of'] : null,
            is_string($input['plan_id'] ?? null) ? $input['plan_id'] : null, $onBehalfOf, $operation);

        return ['batch' => $batch, 'changes' => count($changes)];
    }
}
