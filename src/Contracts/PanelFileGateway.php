<?php

declare(strict_types=1);

namespace Larena\Env\Contracts;

use Larena\Env\Gateway\PanelDownload;
use Larena\Env\Gateway\PanelRequest;

/**
 * A gateway that can hand a site's file over (file:download, simai-env 1.39.0). The answer is a JSON line followed by
 * exactly `size` bytes; they are written to a local file and checked against the answer's size before
 * anything of them is shown. A driver never throws: a refusal or a mismatch comes back as a reason code.
 */
interface PanelFileGateway
{
    public function download(PanelRequest $request, string $into): PanelDownload;
}
