<?php

declare(strict_types=1);

// The panel's own sign-in words in Spanish (C278), over Auth's: the tab title, and «Забыли пароль?» while mail is off.
return [
    'title' => 'Entrar — Larena Panel',
    'recovery_without_mail' => 'Diríjase a otro administrador del panel: él le pondrá una contraseña nueva. Si no hay otro administrador, ponga la contraseña en el servidor: entre en el servidor por SSH (ssh -t) y ejecute «sudo /root/simai-env/simai-admin.sh panel reset-password --confirm yes»; el comando le pedirá el correo y la contraseña nueva.',
    'recovery' => [
        'request' => [
            'sent' => 'Si hay una cuenta con este correo, en un minuto llegará un mensaje con un enlace. El enlace sirve una sola vez y deja de funcionar a los 30 minutos.',
        ],
        'unavailable' => [
            'heading' => 'El correo del panel no está configurado',
            'description' => 'No se puede enviar un enlace para una contraseña nueva. Diríjase a otro administrador del panel. Si no hay ninguno, ponga la contraseña en el servidor: entre en el servidor por SSH (ssh -t) y ejecute «sudo /root/simai-env/simai-admin.sh panel reset-password --confirm yes»; el comando le pedirá el correo y la contraseña nueva.',
        ],
    ],
    'sign_in' => [
        'forgot_page' => [
            'only_admin' => 'Si es el único administrador, entre en el servidor por SSH (ssh -t) y ejecute este comando; le pedirá el correo y la contraseña nueva:',
            'command' => 'sudo /root/simai-env/simai-admin.sh panel reset-password --confirm yes',
        ],
    ],
    'users' => ['navigation' => 'Usuarios del panel'],
];
