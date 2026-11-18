<?php

declare(strict_types=1);

// The panel's people (the panel's UX audit): its users beside the site owners.
return [
    // The sign-in page's tab title, in the panel's one format «page — application» (the panel's UX audit, HEAD-03).
    'title' => 'Sign in — Larena Panel',
    // «Забыли пароль?» until the panel sends mail (owner decision C335): whom to ask, no link that leads nowhere.
    'recovery_without_mail' => 'Ask another administrator of the panel: they will set you a new password.',
    'users' => ['navigation' => 'Panel users'],
];
