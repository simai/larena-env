<?php

declare(strict_types=1);

// The panel's people (the panel's UX audit): its users beside the site owners, not «Пользователи» twice.
return [
    // The sign-in page's tab title, in the panel's one format «page — application» (the panel's UX audit, HEAD-03).
    'title' => 'Вход — Larena Panel',
    // «Забыли пароль?» until the panel sends mail (owner decision C335): whom to ask, and the server's console when
    // there is nobody else (`simai-admin.sh panel reset-password`, which runs `larena:panel-reset-password`); no link that leads nowhere.
    'recovery_without_mail' => 'Обратитесь к другому администратору панели: он задаст вам новый пароль. Если других администраторов нет, задайте пароль на сервере: войдите на сервер по SSH (ssh -t) и выполните «sudo /root/simai-env/simai-admin.sh panel reset-password --confirm yes» — команда спросит адрес и новый пароль.',
    // While mail is off: the console's way too (auditor's addition to C335), the same words on the recovery page itself.
    'recovery' => [
        'request' => [
            'sent' => 'Если такой адрес есть, письмо со ссылкой придёт в течение минуты. Ссылка сработает один раз и через 30 минут перестанет действовать.',
        ],
        'unavailable' => [
            'heading' => 'Почта панели не настроена',
            'description' => 'Ссылку для нового пароля прислать нельзя. Обратитесь к другому администратору панели. Если других нет, задайте пароль на сервере: войдите на сервер по SSH (ssh -t) и выполните «sudo /root/simai-env/simai-admin.sh panel reset-password --confirm yes» — команда спросит адрес и новый пароль.',
        ],
    ],
    'users' => ['navigation' => 'Пользователи панели'],
];
