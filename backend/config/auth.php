<?php
declare(strict_types=1);

function startAppSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_set_cookie_params([
        'httponly' => true,
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'samesite' => 'Lax',
    ]);
    session_start();
}

function isLocalDemoRequest(): bool
{
    $remoteAddress = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!in_array($remoteAddress, ['127.0.0.1', '::1'], true)) {
        return false;
    }

    $hostHeader = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $host = preg_replace('/:\\d+$/', '', $hostHeader);
    if (str_starts_with($hostHeader, '[')) {
        $host = trim((string) strstr($hostHeader, ']', true), '[]');
    }

    return in_array($host, ['localhost', '127.0.0.1', '::1'], true)
        || preg_match('/\\.(?:localhost|local|test)$/', $host) === 1;
}

function requireRole(string ...$roles): array
{
    startAppSession();

    if (isLocalDemoRequest()) {
        return [
            'id' => 0,
            'name' => 'Modo demostración',
            'email' => 'demo@localhost',
            'role' => in_array('admin', $roles, true) ? 'admin' : $roles[0],
        ];
    }

    $user = $_SESSION['user'] ?? null;

    if (!is_array($user) || !in_array($user['role'] ?? '', $roles, true)) {
        header('Location: login.php');
        exit;
    }

    try {
        require_once __DIR__ . '/database.php';
        $statement = getDbConnection()->prepare('SELECT `name`, `email`, `role` FROM `users` WHERE `id` = :id AND `is_active` = 1 LIMIT 1');
        $statement->execute([':id' => $user['id']]);
        $activeUser = $statement->fetch();
    } catch (Throwable) {
        $activeUser = false;
    }

    if (!$activeUser || !in_array($activeUser['role'], $roles, true)) {
        unset($_SESSION['user']);
        header('Location: login.php');
        exit;
    }

    $_SESSION['user'] = [
        'id' => (int) $user['id'],
        'name' => $activeUser['name'],
        'email' => $activeUser['email'],
        'role' => $activeUser['role'],
    ];

    return $_SESSION['user'];
}

function appCsrfToken(): string
{
    startAppSession();
    if (empty($_SESSION['app_csrf'])) {
        $_SESSION['app_csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['app_csrf'];
}

function verifyAppCsrfToken(mixed $token): bool
{
    startAppSession();
    return is_string($token)
        && isset($_SESSION['app_csrf'])
        && hash_equals($_SESSION['app_csrf'], $token);
}