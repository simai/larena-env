<?php

declare(strict_types=1);

// The panel's own sign-in words in Japanese (C278), over Auth's: the tab title, and «Забыли пароль?» while mail is off.
return [
    'title' => 'ログイン — Larena Panel',
    'recovery_without_mail' => 'パネルのほかの管理者に連絡してください。新しいパスワードを設定してもらえます。ほかに管理者がいない場合は、サーバーでパスワードを設定します。SSH（ssh -t）でサーバーにログインし、「sudo /root/simai-env/simai-admin.sh panel reset-password --confirm yes」を実行してください。メールアドレスと新しいパスワードを尋ねられます。',
    'recovery' => [
        'request' => [
            'sent' => 'このアドレスのアカウントがあれば、1 分以内にリンク付きのメールが届きます。リンクは 1 回だけ使え、30 分後に無効になります。',
        ],
        'unavailable' => [
            'heading' => 'パネルのメールが設定されていません',
            'description' => '新しいパスワード用のリンクを送れません。パネルのほかの管理者に連絡してください。いない場合は、サーバーでパスワードを設定します。SSH（ssh -t）でサーバーにログインし、「sudo /root/simai-env/simai-admin.sh panel reset-password --confirm yes」を実行してください。メールアドレスと新しいパスワードを尋ねられます。',
        ],
    ],
    'sign_in' => [
        'forgot_page' => [
            'only_admin' => '管理者があなた一人だけの場合は、SSH（ssh -t）でサーバーにログインして次のコマンドを実行してください。メールアドレスと新しいパスワードを尋ねられます：',
            'command' => 'sudo /root/simai-env/simai-admin.sh panel reset-password --confirm yes',
        ],
    ],
    'users' => ['navigation' => 'パネルのユーザー'],
];
