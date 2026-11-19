<?php

declare(strict_types=1);

// The panel's own sign-in words in Italian (C278), over Auth's: the tab title, and «Забыли пароль?» while mail is off.
return [
    'title' => 'Accedi — Larena Panel',
    'recovery_without_mail' => 'Rivolgiti a un altro amministratore del pannello: ti imposterà una nuova password. Se non ci sono altri amministratori, imposta la password sul server: accedi al server via SSH (ssh -t) ed esegui «sudo /root/simai-env/simai-admin.sh panel reset-password --confirm yes»: il comando chiede l’indirizzo e la nuova password.',
    'recovery' => [
        'request' => [
            'sent' => 'Se a questo indirizzo corrisponde un account, entro un minuto arriva un’email con un link. Il link funziona una sola volta e smette di valere dopo 30 minuti.',
        ],
        'unavailable' => [
            'heading' => 'L’email del pannello non è configurata',
            'description' => 'Non è possibile inviare un link per una nuova password. Rivolgiti a un altro amministratore del pannello. Se non ce ne sono, imposta la password sul server: accedi al server via SSH (ssh -t) ed esegui «sudo /root/simai-env/simai-admin.sh panel reset-password --confirm yes»: il comando chiede l’indirizzo e la nuova password.',
        ],
    ],
    'sign_in' => [
        'forgot_page' => [
            'only_admin' => 'Se sei l’unico amministratore, accedi al server via SSH (ssh -t) ed esegui questo comando, che chiede l’indirizzo e la nuova password:',
            'command' => 'sudo /root/simai-env/simai-admin.sh panel reset-password --confirm yes',
        ],
    ],
    'users' => ['navigation' => 'Utenti del pannello'],
];
