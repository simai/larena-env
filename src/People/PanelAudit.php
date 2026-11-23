<?php

declare(strict_types=1);

namespace Larena\Env\People;

use Illuminate\Contracts\Container\Container;
use Larena\Audit\Contracts\AuditEvent;
use Larena\Audit\Contracts\AuditSink;
use Larena\Audit\Enums\AuditRetentionClass;
use Larena\Audit\Enums\AuditSeverity;
use Larena\Env\Providers\EnvDeclarations;
use InvalidArgumentException;

/**
 * The panel's own events in the audit log (audit.yaml): who gave a person a space, who looked at the panel as someone
 * else, and every change asked of the server with its chain (`env.server.change_started`: who, the request, the plan,
 * the job and the server's operation id). The gateway logs every request beside it, with `impersonated_by` while someone
 * acts for a person. larena/audit is required; where its sink is not bound, a change of the server is refused before it
 * is asked (PanelOperations, `audit_unavailable`) and the rest of the panel's own events are not kept.
 */
final readonly class PanelAudit
{
    public function __construct(private Container $container)
    {
    }

    /** Whether the audit log can be written: a change of the server is not asked without it. */
    public function available(): bool
    {
        return interface_exists(AuditSink::class) && $this->container->bound(AuditSink::class);
    }

    /** @param array<string, string> $payload */
    public function record(string $type, string $actor, string $subject, array $payload = [], ?string $correlationId = null): void
    {
        // Only what audit.yaml declares is written (EnvDeclarations): a new event is declared first.
        if (!EnvDeclarations::writes($type)) {
            throw new InvalidArgumentException('env_audit_event_undeclared: '.$type);
        }
        if (!$this->available()) {
            return;
        }
        $this->container->make(AuditSink::class)->write(AuditEvent::create(
            sourcePackage: 'larena/env',
            category: 'security',
            type: $type,
            actor: $actor,
            subject: $subject,
            severity: AuditSeverity::Notice,
            retentionClass: AuditRetentionClass::Security,
            correlationId: $correlationId ?? bin2hex(random_bytes(12)),
            payload: $payload,
        ));
    }
}
