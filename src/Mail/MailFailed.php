<?php

declare(strict_types=1);

namespace Larena\Env\Mail;

use RuntimeException;

/** Why the panel's mail could not do what was asked, by a reason the screens say in words (`mail.reasons.<reason>`). */
final class MailFailed extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
