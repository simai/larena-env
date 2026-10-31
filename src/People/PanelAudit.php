<?php

declare(strict_types=1);

namespace Larena\Env\People;

use Illuminate\Contracts\Container\Container;
use Larena\Audit\Contracts\AuditEvent;
use Larena\Audit\Contracts\AuditSink;
use Larena\Audit\Enums\AuditRetentionClass;
use Larena\Audit\Enums\AuditSeverity;

/**
 * The panel's own events in the audit log (audit.yaml): who gave a person a space, and who looked at the panel as
 * someone else. The gateway logs every request beside it, with `impersonated_by` while someone acts for a person.
 * Without larena/audit the events are not kept; nothing else changes.
 */
final readonly class PanelAudit
{
    public function __construct(private Container $container)
    {
    }

    /** @param array<string, string> $payload */
    public function record(string $type, string $actor, string $subject, array $payload = []): void
    {
        if (!interface_exists(AuditSink::class) || !$this->container->bound(AuditSink::class)) {
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
            correlationId: bin2hex(random_bytes(12)),
            payload: $payload,
        ));
    }
}
