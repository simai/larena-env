<?php

declare(strict_types=1);

// The panel's people (the panel's UX audit): its users beside the site owners, not «Пользователи» twice.
return [
    // The sign-in page's tab title, in the panel's one format «page — application» (the panel's UX audit, HEAD-03).
    'title' => 'Вход — Larena Panel',
    // «Забыли пароль?» until the panel sends mail (owner decision C335): whom to ask, no link that leads nowhere.
    'recovery_without_mail' => 'Обратитесь к другому администратору панели: он задаст вам новый пароль.',
    'users' => ['navigation' => 'Пользователи панели'],
];
