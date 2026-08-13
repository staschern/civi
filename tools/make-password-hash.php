<?php
/**
 * Хеш пароля для админки.
 *
 *   php tools/make-password-hash.php
 *   php tools/make-password-hash.php 'мой пароль'
 *
 * Полученную строку целиком вставить в config/config.php
 * в поле admin_password_hash. Сам пароль нигде не сохраняется.
 */
$password = $argv[1] ?? null;

if ($password === null) {
    if (function_exists('readline')) {
        echo "Пароль (ввод виден на экране): ";
        $password = trim((string) readline());
    } else {
        echo "Пароль: ";
        $password = trim((string) fgets(STDIN));
    }
}

if ($password === '' || $password === null) {
    fwrite(STDERR, "Пустой пароль не годится\n");
    exit(1);
}
if (mb_strlen($password) < 8) {
    fwrite(STDERR, "Слишком короткий пароль: нужно хотя бы 8 символов\n");
    exit(1);
}

echo "\n'admin_password_hash' => '" . password_hash($password, PASSWORD_DEFAULT) . "',\n\n";
