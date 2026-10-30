<?php

declare(strict_types=1);

namespace Larena\Env\Console;

use Illuminate\Console\Command;
use Larena\Env\Area\ServerArea;
use Larena\Env\Area\ServerAreaRegistrar;

final class RegisterServerAreaCommand extends Command
{
    protected $signature = 'larena:env:area';

    protected $description = 'Register the panel\'s main scope, organization:server. Safe to run again.';

    public function handle(ServerAreaRegistrar $registrar): int
    {
        $status = $registrar->ensure();
        $this->line(ServerArea::SCOPE_REF.': '.$status);

        return $status === 'archived' ? self::FAILURE : self::SUCCESS;
    }
}
