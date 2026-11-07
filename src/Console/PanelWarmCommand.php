<?php

declare(strict_types=1);

namespace Larena\Env\Console;

use Illuminate\Console\Command;
use Larena\Env\Contracts\PanelGateway;
use Larena\Env\Gateway\CachingPanelGateway;

/**
 * Every minute, asks the server again what the panel's pages asked lately, so a page opens from the kept answers
 * instead of waiting for simai-env (ENV 2026-11-07: 0.7-1 s per ask, the overview about five seconds). Nobody looked
 * for twenty minutes: nothing is asked.
 */
final class PanelWarmCommand extends Command
{
    protected $signature = 'larena:env-warm';

    protected $description = 'Refresh the server answers the panel pages asked lately';

    public function handle(PanelGateway $gateway): int
    {
        if (!$gateway instanceof CachingPanelGateway) {
            return self::SUCCESS;
        }
        $asked = $gateway->warm();
        if ($this->output->isVerbose()) {
            $this->line("Asked again: {$asked}");
        }

        return self::SUCCESS;
    }
}
