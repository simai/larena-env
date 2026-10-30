<?php

declare(strict_types=1);

namespace Larena\Env\Gateway;

/**
 * Reason codes this package adds to the gateway's own. The gateway's are data of its protocol (bad_request, too_large,
 * bad_actor, not_allowed, invalid_argument, owner_scope, ...); larena/core's runner adds gateway_process_failed,
 * gateway_answer_invalid and gateway_protocol_mismatch.
 */
final class PanelReason
{
    public const string BAD_ACTOR = 'bad_actor';
    public const string BAD_REQUEST = 'bad_request';
    public const string INVALID_ARGUMENT = 'invalid_argument';
    public const string NOT_ALLOWED = 'not_allowed';

    public const string NOT_CONFIGURED = 'gateway_not_configured';
    public const string CREDENTIALS_UNREADABLE = 'gateway_credentials_unreadable';
    public const string ANSWER_INVALID = 'gateway_answer_invalid';
    public const string RECORDING_MISSING = 'recording_missing';
    public const string RECORDING_TAMPERED = 'recording_tampered';
    public const string ANSWER_SHAPE_INVALID = 'answer_shape_invalid';

    private function __construct()
    {
    }
}
