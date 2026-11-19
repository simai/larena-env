<?php

declare(strict_types=1);

// The panel's sign-in words in every registry language (C278): its own words over Auth's sign-in screens (the tab
// title and «Забыли пароль?» while mail is off), the first-run link words and the rights' names. Each of the nine
// other languages has every key Russian has, with the same placeholders, the server's commands word for word.
// The panel's pages (admin.php), its letters (mail.php) and its settings words are below, with the same rules except
// that a name or a command may read as in English (Larena Panel, SIMAI ENV, nginx -t, a storage provider).
$flat = static function (array $strings, string $prefix = '') use (&$flat): array {
    $keys = [];
    foreach ($strings as $key => $value) {
        $keys = is_array($value) ? [...$keys, ...$flat($value, $prefix.$key.'.')] : [...$keys, $prefix.$key => (string) $value];
    }

    return $keys;
};
$marks = static function (string $text): array {
    preg_match_all('/:[a-z_]+|<[^>]+>|simai-admin\.sh [a-z -]+--confirm yes/', $text, $found);
    $marks = $found[0];
    sort($marks);

    return $marks;
};
$root = dirname(__DIR__, 2);
$files = ['assembly/lang/vendor/larena-auth/%s/auth.php', 'resources/lang/%s/panel.php', 'resources/lang/%s/operations.php'];
$languages = ['zh-Hans', 'es', 'pt-BR', 'de', 'fr', 'it', 'ja', 'ko', 'ar'];
$auth = (string) file_get_contents($root.'/vendor/larena/auth/config/larena-auth.php');
foreach ($languages as $code) {
    assert(str_contains($auth, "'".$code."'"), $code.' is one of the registry languages');
}
foreach ($files as $pattern) {
    $ru = $flat(require $root.'/'.sprintf($pattern, 'ru'));
    $en = $flat(require $root.'/'.sprintf($pattern, 'en'));
    assert(array_keys($ru) === array_keys($en), sprintf($pattern, 'en').' and ru have the same keys');
    foreach ($languages as $code) {
        $file = $root.'/'.sprintf($pattern, $code);
        assert(is_file($file), sprintf($pattern, $code).' is missing');
        $words = $flat(require $file);
        assert(array_keys($words) === array_keys($ru), sprintf($pattern, $code).': the keys of ru, no more, no fewer: '
            .implode(', ', array_merge(array_diff(array_keys($ru), array_keys($words)), array_diff(array_keys($words), array_keys($ru)))));
        foreach ($ru as $key => $russian) {
            $word = $words[$key];
            assert($word !== '' && ($word !== $en[$key] || $key === 'sign_in.forgot_page.command'), sprintf($pattern, $code).' '.$key.': said in its own language');
            assert($marks($word) === $marks($en[$key]), sprintf($pattern, $code).' '.$key.': the same placeholders and server commands');
            assert(preg_match('/\p{Cyrillic}/u', $word) !== 1, sprintf($pattern, $code).' '.$key.': no Russian left');
        }
    }
}
// The panel's pages, letters and settings words: every key of ru in ru's order, the same placeholders, nothing Russian,
// no sentence left in English, and the words the code reads as data in each language's form.
$pages = ['resources/lang/%s/admin.php', 'resources/lang/%s/mail.php', 'assembly/lang/vendor/larena-admin/%s/settings.php'];
$translated = ['de', 'fr', 'es', 'it', 'pt-BR', 'zh-Hans', 'ja', 'ko'];
foreach ($pages as $pattern) {
    $ru = $flat(require $root.'/'.sprintf($pattern, 'ru'));
    $en = $flat(require $root.'/'.sprintf($pattern, 'en'));
    assert(array_keys($ru) === array_keys($en), sprintf($pattern, 'en').' and ru have the same keys');
    foreach ($translated as $code) {
        $file = $root.'/'.sprintf($pattern, $code);
        assert(is_file($file), sprintf($pattern, $code).' is missing');
        $words = $flat(require $file);
        assert(array_keys($words) === array_keys($ru), sprintf($pattern, $code).': the keys of ru, no more, no fewer: '
            .implode(', ', array_merge(array_keys(array_diff_key($ru, $words)), array_keys(array_diff_key($words, $ru)))));
        foreach ($ru as $key => $russian) {
            $word = $words[$key];
            assert(($en[$key] === '') === ($word === ''), sprintf($pattern, $code).' '.$key.': empty exactly where English is');
            assert($marks($word) === $marks($en[$key]), sprintf($pattern, $code).' '.$key.': the same placeholders and server commands');
            assert(preg_match('/\p{Cyrillic}/u', $word) !== 1, sprintf($pattern, $code).' '.$key.': no Russian left');
            $left = preg_split('/\s+/u', trim((string) preg_replace('/:[a-z_]+|[^\p{L}\s]/u', '', $word)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            assert(count($left) <= 1 || $word !== $en[$key] || str_contains($word, 'Larena Panel') || in_array($key, ['status.env',
                'doctor.checks.nginx_t', 'doctor.areas.nginx', 'runtime.web_server', 'services.names.nginx', 'services.names.apache2',
                'security.mcp.clients.claude_code', 'services.unisender', 'sites.list.certificate_ends', 'sites.list.certificate_left',
                'services.mailru', 'panel_settings.rows.updates_value'], true) || str_starts_with($key, 'storages.providers.'), sprintf($pattern, $code).' '.$key.': not left in English');
        }
    }
}
$decimal = ['de' => ',', 'fr' => ',', 'es' => ',', 'it' => ',', 'pt-BR' => ',', 'zh-Hans' => '.', 'ja' => '.', 'ko' => '.', 'ar' => '.'];
foreach ($translated as $code) {
    $words = require $root.'/'.sprintf($pages[0], $code);
    assert($words['units']['rule'] === 'en', $code.': days counted one / many, as every language but Russian');
    assert($words['bytes']['decimal'] === $decimal[$code], $code.': its decimal separator');
}
foreach ($languages as $code) {
    $words = require $root.'/'.sprintf($files[0], $code);
    assert($words['sign_in']['forgot_page']['command'] === 'sudo /root/simai-env/simai-admin.sh panel reset-password --confirm yes', $code.': the command to copy');
    assert(str_ends_with($words['title'], ' — Larena Panel'), $code.': the tab title «page — application», the product name as it is');
}

echo "PanelTranslationsTest passed.\n";
