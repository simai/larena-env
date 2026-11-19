<?php

declare(strict_types=1);

// The panel's own sign-in words in French (C278), over Auth's: the tab title, and «Забыли пароль?» while mail is off.
return [
    'title' => 'Connexion — Larena Panel',
    'recovery_without_mail' => 'Adressez-vous à un autre administrateur du panneau : il vous définira un nouveau mot de passe. S’il n’y a pas d’autre administrateur, définissez le mot de passe sur le serveur : connectez-vous au serveur en SSH (ssh -t) et lancez « sudo /root/simai-env/simai-admin.sh panel reset-password --confirm yes » — la commande demande l’adresse et le nouveau mot de passe.',
    'recovery' => [
        'request' => [
            'sent' => 'Si un compte utilise cette adresse, un e-mail avec un lien arrive dans la minute. Le lien ne fonctionne qu’une fois et n’est plus valable après 30 minutes.',
        ],
        'unavailable' => [
            'heading' => 'La messagerie du panneau n’est pas configurée',
            'description' => 'Impossible d’envoyer un lien pour un nouveau mot de passe. Adressez-vous à un autre administrateur du panneau. S’il n’y en a pas, définissez le mot de passe sur le serveur : connectez-vous au serveur en SSH (ssh -t) et lancez « sudo /root/simai-env/simai-admin.sh panel reset-password --confirm yes » — la commande demande l’adresse et le nouveau mot de passe.',
        ],
    ],
    'sign_in' => [
        'forgot_page' => [
            'only_admin' => 'Si vous êtes le seul administrateur, connectez-vous au serveur en SSH (ssh -t) et lancez cette commande — elle demande l’adresse et le nouveau mot de passe :',
            'command' => 'sudo /root/simai-env/simai-admin.sh panel reset-password --confirm yes',
        ],
    ],
    'users' => ['navigation' => 'Utilisateurs du panneau'],
];
