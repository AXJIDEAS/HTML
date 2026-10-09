<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function registrationResponse(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    registrationResponse(405, ['success' => false, 'message' => 'Método no permitido.']);
}

try {
    if (!verifyAppCsrfToken($_POST['csrf_token'] ?? null)) {
        registrationResponse(400, ['success' => false, 'message' => 'La sesión venció. Recarga la página e inténtalo de nuevo.']);
    }

    $pdo = getDbConnection();
    if ((int) $pdo->query('SELECT COUNT(*) FROM `users`')->fetchColumn() === 0) {
        registrationResponse(409, ['success' => false, 'message' => 'Primero debe configurarse la cuenta inicial de administrador.']);
    }

    $name = trim((string) ($_POST['name'] ?? ''));
    $email = filter_var(trim((string) ($_POST['email'] ?? '')), FILTER_VALIDATE_EMAIL);
    $password = (string) ($_POST['password'] ?? '');
    $phone = trim((string) ($_POST['phone'] ?? ''));

    if ($name === '' || strlen($name) > 150 || !$email || strlen($password) < 12 || strlen($password) > 4096) {
        registrationResponse(422, ['success' => false, 'message' => 'Completa nombre y correo válidos. La contraseña debe tener al menos 12 caracteres.']);
    }
    if (strlen($phone) > 50) {
        registrationResponse(422, ['success' => false, 'message' => 'El teléfono excede la longitud permitida.']);
    }

    $statement = $pdo->prepare("INSERT INTO `users` (`name`, `email`, `phone`, `role`, `password_hash`, `is_active`) VALUES (:name, :email, :phone, 'receptionist', :password, 0)");
    $statement->execute([
        ':name' => $name,
        ':email' => $email,
        ':phone' => $phone !== '' ? $phone : null,
        ':password' => password_hash($password, PASSWORD_DEFAULT),
    ]);

    registrationResponse(201, [
        'success' => true,
        'message' => 'Solicitud enviada. Un administrador debe activar tu cuenta antes de que puedas iniciar sesión.',
    ]);
} catch (PDOException $exception) {
    if ((string) $exception->getCode() === '23000') {
        registrationResponse(409, ['success' => false, 'message' => 'Ya existe una cuenta con ese correo.']);
    }
    error_log('Staff registration failed: ' . $exception->getMessage());
    registrationResponse(503, ['success' => false, 'message' => 'No se pudo enviar la solicitud. Inténtalo más tarde.']);
} catch (Throwable $exception) {
    error_log('Staff registration failed: ' . $exception->getMessage());
    registrationResponse(503, ['success' => false, 'message' => 'No se pudo enviar la solicitud. Inténtalo más tarde.']);
}