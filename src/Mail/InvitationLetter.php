<?php

declare(strict_types=1);

namespace Larena\Env\Mail;

/**
 * What the invitation letter says besides its link (plan «Приглашение»): who invites and what the person will be able to
 * do. The «Люди» screen fills it right before it asks the sign-in package to invite, which then calls the panel's
 * postman (PanelInvitationDelivery) within the same request; one per request, never kept.
 */
final class InvitationLetter
{
    public ?string $inviter = null;

    public ?string $level = null;

    public function about(string $inviter, string $level): void
    {
        $this->inviter = $inviter;
        $this->level = $level;
    }
}
