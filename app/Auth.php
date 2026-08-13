<?php
declare(strict_types=1);

namespace Civi;

/**
 * Вход по одному паролю: пользователей в системе нет, админка одна.
 * Пароль нигде не хранится — только его хеш в config.php.
 */
final class Auth
{
    /** @var array */
    private $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_name($this->config['session_name'] ?? 'civi_admin');
        session_set_cookie_params([
            'lifetime' => (int) ($this->config['session_lifetime'] ?? 43200),
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => $this->isHttps(),
        ]);
        session_start();
    }

    public function isLoggedIn(): bool
    {
        return !empty($_SESSION['authenticated']);
    }

    /** Возвращает false при неверном пароле или незаполненном хеше. */
    public function login(string $password): bool
    {
        $hash = (string) ($this->config['admin_password_hash'] ?? '');
        if ($hash === '' || !password_verify($password, $hash)) {
            return false;
        }

        session_regenerate_id(true);
        $_SESSION['authenticated'] = true;
        $_SESSION['csrf'] = bin2hex(random_bytes(32));

        return true;
    }

    public function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'],
                $params['secure'], $params['httponly']);
        }
        session_destroy();
    }

    public function csrfToken(): string
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf'];
    }

    public function checkCsrf(?string $token): bool
    {
        return is_string($token)
            && !empty($_SESSION['csrf'])
            && hash_equals((string) $_SESSION['csrf'], $token);
    }

    private function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }

        // за nginx-прокси схема приходит заголовком
        return (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    }
}
