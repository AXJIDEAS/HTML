<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

startAppSession();
if (!empty($_SESSION['user'])) {
    $destination = $_SESSION['user']['role'] === 'admin' ? 'index.php' : 'reception.php';
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => true, 'redirect' => $destination]);
        exit;
    }
    header('Location: ' . $destination);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Location: access.html');
    exit;
}

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = getDbConnection();
    $setupMode = (int) $pdo->query('SELECT COUNT(*) FROM `users`')->fetchColumn() === 0;

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        if (!verifyAppCsrfToken($_POST['csrf_token'] ?? null)) {
            throw new RuntimeException('La sesión venció. Recarga la página e inténtalo de nuevo.');
        }

        $email = filter_var(trim((string) ($_POST['email'] ?? '')), FILTER_VALIDATE_EMAIL);
        $password = (string) ($_POST['password'] ?? '');

        if (!$email || $password === '') {
            throw new RuntimeException('Introduce un correo y una contraseña válidos.');
        }

        if ($setupMode) {
            $setupToken = getenv('ADMIN_SETUP_TOKEN') ?: '';
            $providedToken = (string) ($_POST['setup_token'] ?? '');
            $name = trim((string) ($_POST['name'] ?? ''));
            if ($setupToken === '' || !hash_equals($setupToken, $providedToken)) {
                throw new RuntimeException('El token de instalación no es válido o no está configurado en el servidor.');
            }
            if ($name === '' || strlen($password) < 12) {
                throw new RuntimeException('Indica tu nombre y una contraseña de al menos 12 caracteres.');
            }

            $statement = $pdo->prepare('INSERT INTO `users` (`name`, `email`, `role`, `password_hash`) VALUES (:name, :email, \'admin\', :password)');
            $statement->execute([':name' => $name, ':email' => $email, ':password' => password_hash($password, PASSWORD_DEFAULT)]);
            $user = ['id' => (int) $pdo->lastInsertId(), 'name' => $name, 'email' => $email, 'role' => 'admin'];
        } else {
            $statement = $pdo->prepare('SELECT `id`, `name`, `email`, `role`, `password_hash` FROM `users` WHERE `email` = :email AND `is_active` = 1 LIMIT 1');
            $statement->execute([':email' => $email]);
            $record = $statement->fetch();
            if (!$record || !password_verify($password, $record['password_hash'])) {
                throw new RuntimeException('Correo o contraseña incorrectos.');
            }
            $user = ['id' => (int) $record['id'], 'name' => $record['name'], 'email' => $record['email'], 'role' => $record['role']];
        }

        session_regenerate_id(true);
        $_SESSION['user'] = $user;
        unset($_SESSION['app_csrf']);
        echo json_encode([
            'success' => true,
            'redirect' => $user['role'] === 'admin' ? 'index.php' : 'reception.php',
        ]);
        exit;
    }
} catch (Throwable $exception) {
    $message = $exception instanceof RuntimeException
        ? $exception->getMessage()
        : 'No se pudo completar el acceso. Verifica la configuración de base de datos.';
    http_response_code($exception instanceof RuntimeException ? 422 : 503);
    echo json_encode(['success' => false, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}