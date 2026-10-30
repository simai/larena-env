<?php

declare(strict_types=1);

namespace Larena\Env\Gateway;

/** A gateway answer (simai-panel-answer/1) that has been read: a known status and, unless it is ok, a reason code. */
final readonly class PanelAnswer
{
    private const array KINDS = ['result', 'plan', 'job'];
    private const string REASON_PATTERN = '/\A[A-Za-z0-9][A-Za-z0-9._-]{0,127}\z/D';

    /** @param array<array-key, mixed> $data */
    private function __construct(
        public string $requestId,
        public PanelAnswerStatus $status,
        public ?string $kind = null,
        public array $data = [],
        public ?string $reason = null,
        public ?string $commandStatus = null,
        public ?string $operationId = null,
    ) {
    }

    public static function refused(string $requestId, string $reason): self
    {
        return new self($requestId, PanelAnswerStatus::Refused, reason: $reason);
    }

    public static function failed(string $requestId, string $reason): self
    {
        return new self($requestId, PanelAnswerStatus::Failed, reason: $reason);
    }

    /**
     * Reads the fields of an answer; a shape the protocol does not have is a failed answer, not an exception.
     *
     * @param array<array-key, mixed> $document
     */
    public static function fromDocument(string $requestId, array $document): self
    {
        $status = is_string($document['status'] ?? null) ? PanelAnswerStatus::tryFrom($document['status']) : null;
        if ($status === null) {
            return self::failed($requestId, PanelReason::ANSWER_INVALID);
        }
        if ($status !== PanelAnswerStatus::Ok) {
            $reason = $document['reason'] ?? null;
            if (!is_string($reason) || preg_match(self::REASON_PATTERN, $reason) !== 1) {
                return self::failed($requestId, PanelReason::ANSWER_INVALID);
            }

            return new self($requestId, $status, reason: $reason, operationId: self::text($document['operation_id'] ?? null));
        }
        $kind = $document['kind'] ?? null;
        $data = $document['data'] ?? [];
        if (!in_array($kind, self::KINDS, true) || !is_array($data) || ($kind === 'result' && !isset($document['data']))) {
            return self::failed($requestId, PanelReason::ANSWER_INVALID);
        }

        return new self($requestId, $status, $kind, $data, null, self::text($document['command_status'] ?? null), self::text($document['operation_id'] ?? null));
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
