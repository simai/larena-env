<?php

declare(strict_types=1);

namespace Larena\Env\Console;

use Illuminate\Console\Command;
use Larena\Env\Mail\MailLetters;
use Larena\Env\Mail\PanelMail;
use Larena\Env\Mail\PanelMailer;

/**
 * The panel's letter carrier (owner decision C335, step 2), run by the panel's scheduler each minute: sends the letters
 * that wait, tries a failed one again after 1, 5 and 30 minutes and then gives it up as «не доставлено», and forgets the
 * record older than 90 days. Letters with a sign-in link never wait here; they go at once.
 */
final class PanelMailCommand extends Command
{
    protected $signature = 'larena:env-mail';

    protected $description = 'Send the panel\'s waiting letters (every minute).';

    public function handle(MailLetters $letters, PanelMail $mail): int
    {
        $done = $letters->run($mail, new PanelMailer());
        $pruned = $letters->prune();
        $this->line(sprintf('letters sent: %d, to try again: %d, not delivered: %d, forgotten: %d', $done['sent'], $done['retried'], $done['failed'], $pruned));

        return self::SUCCESS;
    }
}
