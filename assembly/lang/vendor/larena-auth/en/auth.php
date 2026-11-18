<?php

declare(strict_types=1);

// The panel's people (the panel's UX audit): its users beside the site owners.
return [
    // The sign-in page's tab title, in the panel's one format «page — application» (the panel's UX audit, HEAD-03).
    'title' => 'Sign in — Larena Panel',
    // «Забыли пароль?» until the panel sends mail (owner decision C335): whom to ask, and the server's console when
    // there is nobody else (`simai-admin.sh panel reset-password`, which runs `larena:panel-reset-password`); no link that leads nowhere.
    'recovery_without_mail' => 'Ask another administrator of the panel: they will set you a new password. If there is no other administrator, set the password on the server: sign in to the server over SSH (ssh -t) and run «sudo /root/simai-env/simai-admin.sh panel reset-password --confirm yes» — it asks for the address and the new password.',
    // While mail is off: the console's way too (auditor's addition to C335), the same words on the recovery page itself.
    'recovery' => [
        'request' => [
            'sent' => 'If the address has an account, a letter with a link arrives within a minute. The link works once and stops working after 30 minutes.',
        ],
        'unavailable' => [
            'heading' => 'The panel\'s mail is not set up',
            'description' => 'A link for a new password cannot be sent. Ask another administrator of the panel. If there is none, set the password on the server: sign in to the server over SSH (ssh -t) and run «sudo /root/simai-env/simai-admin.sh panel reset-password --confirm yes» — it asks for the address and the new password.',
        ],
    ],
    'users' => ['navigation' => 'Panel users'],
];
