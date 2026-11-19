<?php

declare(strict_types=1);

// The panel's own sign-in words in Arabic (C278), over Auth's: the tab title, and «Забыли пароль?» while mail is off.
return [
    'title' => 'تسجيل الدخول — Larena Panel',
    'recovery_without_mail' => 'تواصل مع مسؤول آخر في اللوحة، وسيضع لك كلمة مرور جديدة. وإن لم يكن هناك مسؤول آخر فاضبط كلمة المرور على الخادم: ادخل إلى الخادم عبر SSH (ssh -t) ونفّذ «sudo /root/simai-env/simai-admin.sh panel reset-password --confirm yes»، وسيسألك الأمر عن العنوان وكلمة المرور الجديدة.',
    'recovery' => [
        'request' => [
            'sent' => 'إن كان لهذا العنوان حساب، فستصلك خلال دقيقة رسالة فيها رابط. يعمل الرابط مرة واحدة ويتوقف عن العمل بعد 30 دقيقة.',
        ],
        'unavailable' => [
            'heading' => 'بريد اللوحة غير مُعدّ',
            'description' => 'لا يمكن إرسال رابط لكلمة مرور جديدة. تواصل مع مسؤول آخر في اللوحة. وإن لم يوجد فاضبط كلمة المرور على الخادم: ادخل إلى الخادم عبر SSH (ssh -t) ونفّذ «sudo /root/simai-env/simai-admin.sh panel reset-password --confirm yes»، وسيسألك الأمر عن العنوان وكلمة المرور الجديدة.',
        ],
    ],
    'sign_in' => [
        'forgot_page' => [
            'only_admin' => 'إن كنت المسؤول الوحيد، فادخل إلى الخادم عبر SSH (ssh -t) ونفّذ هذا الأمر، وسيسألك عن العنوان وكلمة المرور الجديدة:',
            'command' => 'sudo /root/simai-env/simai-admin.sh panel reset-password --confirm yes',
        ],
    ],
    'users' => ['navigation' => 'مستخدمو اللوحة'],
];
