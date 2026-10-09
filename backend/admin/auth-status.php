<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (isLocalDemoRequest()) {
    echo json_encode([
        'success' => true,
        'csrf_token' => appCsrfToken(),
        'setup_required' => false,
        'registration_enabled' => false,
        'redirect' => 'index.php',
        'demo_mode' => true,
    ]);
    exit;
}

try {
    $pdo = getDbConnection();
    $count = (int) $pdo->query('SELECT COUNT(*) FROM `users`')->fetchColumn();
    $payload = [
        'success' => true,
        'csrf_token' => appCsrfToken(),
        'setup_required' => $count === 0,
        'registration_enabled' => $count > 0,
    ];
    if (!empty($_SESSION['user'])) {
        $payload['redirect'] = $_SESSION['user']['role'] === 'admin' ? 'index.php' : 'reception.php';
    }
    echo json_encode($payload);
} catch (Throwable) {
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => 'No se pudo conectar con el sistema. Inténtalo más tarde.'], JSON_UNESCAPED_UNICODE);
}