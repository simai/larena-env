<?php

declare(strict_types=1);

// The panel's own sign-in words in Brazilian Portuguese (C278), over Auth's: the tab title, and «Забыли пароль?» while mail is off.
return [
    'title' => 'Entrar — Larena Panel',
    'recovery_without_mail' => 'Fale com outro administrador do painel: ele vai definir uma nova senha para você. Se não houver outro administrador, defina a senha no servidor: entre no servidor por SSH (ssh -t) e execute “sudo /root/simai-env/simai-admin.sh panel reset-password --confirm yes” — o comando pede o endereço e a nova senha.',
    'recovery' => [
        'request' => [
            'sent' => 'Se houver uma conta com este endereço, um e-mail com um link chega em até um minuto. O link funciona uma única vez e deixa de valer depois de 30 minutos.',
        ],
        'unavailable' => [
            'heading' => 'O e-mail do painel não está configurado',
            'description' => 'Não é possível enviar um link para uma nova senha. Fale com outro administrador do painel. Se não houver nenhum, defina a senha no servidor: entre no servidor por SSH (ssh -t) e execute “sudo /root/simai-env/simai-admin.sh panel reset-password --confirm yes” — o comando pede o endereço e a nova senha.',
        ],
    ],
    'sign_in' => [
        'forgot_page' => [
            'only_admin' => 'Se você é o único administrador, entre no servidor por SSH (ssh -t) e execute este comando — ele pede o endereço e a nova senha:',
            'command' => 'sudo /root/simai-env/simai-admin.sh panel reset-password --confirm yes',
        ],
    ],
    'users' => ['navigation' => 'Usuários do painel'],
];
