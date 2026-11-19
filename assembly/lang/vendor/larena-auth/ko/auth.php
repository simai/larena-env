<?php

declare(strict_types=1);

// The panel's own sign-in words in Korean (C278), over Auth's: the tab title, and «Забыли пароль?» while mail is off.
return [
    'title' => '로그인 — Larena Panel',
    'recovery_without_mail' => '패널의 다른 관리자에게 문의해 주세요. 새 비밀번호를 정해 줄 수 있습니다. 다른 관리자가 없으면 서버에서 비밀번호를 정하세요. SSH(ssh -t)로 서버에 접속한 뒤 ‘sudo /root/simai-env/simai-admin.sh panel reset-password --confirm yes’를 실행하면 이메일 주소와 새 비밀번호를 묻습니다.',
    'recovery' => [
        'request' => [
            'sent' => '이 주소로 된 계정이 있으면 1분 안에 링크가 담긴 이메일이 도착합니다. 링크는 한 번만 쓸 수 있고 30분 뒤에는 사용할 수 없습니다.',
        ],
        'unavailable' => [
            'heading' => '패널 이메일이 설정되어 있지 않습니다',
            'description' => '새 비밀번호 링크를 보낼 수 없습니다. 패널의 다른 관리자에게 문의해 주세요. 다른 관리자가 없으면 서버에서 비밀번호를 정하세요. SSH(ssh -t)로 서버에 접속한 뒤 ‘sudo /root/simai-env/simai-admin.sh panel reset-password --confirm yes’를 실행하면 이메일 주소와 새 비밀번호를 묻습니다.',
        ],
    ],
    'sign_in' => [
        'forgot_page' => [
            'only_admin' => '관리자가 혼자뿐이라면 SSH(ssh -t)로 서버에 접속해 다음 명령을 실행하세요. 이메일 주소와 새 비밀번호를 묻습니다:',
            'command' => 'sudo /root/simai-env/simai-admin.sh panel reset-password --confirm yes',
        ],
    ],
    'users' => ['navigation' => '패널 사용자'],
];
