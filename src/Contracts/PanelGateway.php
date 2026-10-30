<?php

declare(strict_types=1);

namespace Larena\Env\Contracts;

use Larena\Env\Gateway\PanelAnswer;
use Larena\Env\Gateway\PanelRequest;

/**
 * The panel's one way to ask the server. simai-env stays the only component that touches the host; a driver only
 * carries a request to it and brings the answer back. A driver never throws for a refusal or a failure: both come back
 * as an answer with a reason code, so a screen always has something honest to say.
 */
interface PanelGateway
{
    public function ask(PanelRequest $request): PanelAnswer;

    /** Where the answers come from: `recorded`, `sudo`, `ssh` or `none`. The screen says so, recorded data is never taken for a live server. */
    public function source(): string;
}
