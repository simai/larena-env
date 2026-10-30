<?php

declare(strict_types=1);

namespace Larena\Env\Gateway;

use Larena\Env\Contracts\PanelGateway;

/** No usable driver is configured: every question gets the same honest answer. */
final readonly class UnconfiguredGateway implements PanelGateway
{
    public function source(): string
    {
        return 'none';
    }

    public function ask(PanelRequest $request): PanelAnswer
    {
        return PanelAnswer::failed($request->requestId, PanelReason::NOT_CONFIGURED);
    }
}
