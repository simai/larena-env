<?php

declare(strict_types=1);

// The panel's own sign-in words in Simplified Chinese (C278), over Auth's: the tab title, and «Забыли пароль?» while mail is off.
return [
    'title' => '登录 — Larena Panel',
    'recovery_without_mail' => '请联系面板的其他管理员，由他为您设置新密码。如果没有其他管理员，请在服务器上设置密码：通过 SSH（ssh -t）登录服务器并运行“sudo /root/simai-env/simai-admin.sh panel reset-password --confirm yes”，该命令会询问邮箱地址和新密码。',
    'recovery' => [
        'request' => [
            'sent' => '如果此地址有对应的账户，一分钟内会收到一封带链接的邮件。该链接只能使用一次，30 分钟后失效。',
        ],
        'unavailable' => [
            'heading' => '面板邮件尚未设置',
            'description' => '无法发送设置新密码的链接。请联系面板的其他管理员。如果没有，请在服务器上设置密码：通过 SSH（ssh -t）登录服务器并运行“sudo /root/simai-env/simai-admin.sh panel reset-password --confirm yes”，该命令会询问邮箱地址和新密码。',
        ],
    ],
    'sign_in' => [
        'forgot_page' => [
            'only_admin' => '如果您是唯一的管理员，请通过 SSH（ssh -t）登录服务器并运行以下命令，它会询问邮箱地址和新密码：',
            'command' => 'sudo /root/simai-env/simai-admin.sh panel reset-password --confirm yes',
        ],
    ],
    'users' => ['navigation' => '面板用户'],
];
