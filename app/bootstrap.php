<?php
declare(strict_types=1);

/**
 * Загрузка конфигурации, автозагрузчик и общие хелперы вида.
 * Подключается единственной точкой входа public/index.php.
 */

if (PHP_VERSION_ID < 70400) {
    http_response_code(500);
    exit('Требуется PHP 7.4 или новее, установлен ' . PHP_VERSION);
}

foreach (['pdo_mysql', 'json', 'mbstring'] as $ext) {
    if (!extension_loaded($ext)) {
        http_response_code(500);
        exit('Не хватает расширения PHP: ' . $ext);
    }
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'Civi\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $path = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

$configPath = __DIR__ . '/../config/config.php';
if (!is_file($configPath)) {
    http_response_code(500);
    exit('Нет config/config.php — скопируйте config/config.php.example и заполните его.');
}

/** @var array $config */
$config = require $configPath;

if (!empty($config['debug'])) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
}

/** Экранирование для вывода в HTML. */
function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Ссылка внутри админки. */
function url(string $page, array $params = []): string
{
    return 'index.php?' . http_build_query(array_merge(['p' => $page], $params));
}

function redirect(string $page, array $params = []): void
{
    header('Location: ' . url($page, $params));
    exit;
}

/** Однократное сообщение между редиректами. */
function flash(?string $message = null, string $type = 'ok'): ?array
{
    if ($message !== null) {
        $_SESSION['flash'] = ['text' => $message, 'type' => $type];

        return null;
    }
    if (empty($_SESSION['flash'])) {
        return null;
    }
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);

    return $flash;
}

/**
 * Имена служебных переменных начинаются с подчёркиваний намеренно:
 * extract() с EXTR_SKIP не перезапишет переменную, которая уже есть
 * в области видимости, поэтому обычные имена вроде $data или $name
 * молча затеняли бы одноимённые данные вида.
 */
function view(string $__view, array $__vars = []): void
{
    extract($__vars, EXTR_SKIP);
    require __DIR__ . '/views/' . $__view . '.php';
}
