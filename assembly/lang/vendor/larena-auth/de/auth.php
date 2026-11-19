<?php

declare(strict_types=1);

// The panel's own sign-in words in German (C278), over Auth's: the tab title, and «Забыли пароль?» while mail is off.
return [
    'title' => 'Anmelden — Larena Panel',
    'recovery_without_mail' => 'Wenden Sie sich an einen anderen Administrator des Panels: Er legt ein neues Passwort für Sie fest. Gibt es keinen anderen Administrator, legen Sie das Passwort auf dem Server fest: Melden Sie sich per SSH (ssh -t) am Server an und führen Sie „sudo /root/simai-env/simai-admin.sh panel reset-password --confirm yes“ aus — der Befehl fragt nach der Adresse und dem neuen Passwort.',
    'recovery' => [
        'request' => [
            'sent' => 'Wenn es zu dieser Adresse ein Konto gibt, kommt innerhalb einer Minute eine E-Mail mit einem Link. Der Link funktioniert einmal und ist nach 30 Minuten nicht mehr gültig.',
        ],
        'unavailable' => [
            'heading' => 'Die E-Mail des Panels ist nicht eingerichtet',
            'description' => 'Ein Link für ein neues Passwort kann nicht gesendet werden. Wenden Sie sich an einen anderen Administrator des Panels. Gibt es keinen, legen Sie das Passwort auf dem Server fest: Melden Sie sich per SSH (ssh -t) am Server an und führen Sie „sudo /root/simai-env/simai-admin.sh panel reset-password --confirm yes“ aus — der Befehl fragt nach der Adresse und dem neuen Passwort.',
        ],
    ],
    'sign_in' => [
        'forgot_page' => [
            'only_admin' => 'Wenn Sie der einzige Administrator sind, melden Sie sich per SSH (ssh -t) am Server an und führen Sie diesen Befehl aus — er fragt nach der Adresse und dem neuen Passwort:',
            'command' => 'sudo /root/simai-env/simai-admin.sh panel reset-password --confirm yes',
        ],
    ],
    'users' => ['navigation' => 'Benutzer des Panels'],
];
