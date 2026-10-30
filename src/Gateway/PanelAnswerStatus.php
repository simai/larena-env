<?php

declare(strict_types=1);

namespace Larena\Env\Gateway;

enum PanelAnswerStatus: string
{
    case Ok = 'ok';
    /** Not run; the gateway, or this package before sending, said no. */
    case Refused = 'refused';
    /** Ran and failed, or the gateway could not be reached or understood. */
    case Failed = 'failed';
}
