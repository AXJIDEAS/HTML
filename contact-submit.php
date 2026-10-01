<?php
declare(strict_types=1);

/**
 * ========================================================
 * Hotel Ombara - Contact & Membership Submission (contact-submit.php)
 * ========================================================
 * Conectado a MySQL (tabla memberships) con fallback seguro para correo.
 */

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');

require_once __DIR__ . '/db-config.php';

function respond(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function sanitizeLine(string $value): string
{
    return trim((string) preg_replace("/[\r\n]+/", ' ', $value));
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    respond(405, [
        'success' => false,
        'message' => 'Método de petición inválido.',
    ]);
}

$name = sanitizeLine((string) ($_POST['name'] ?? $_POST['fullName'] ?? ''));
$email = trim((string) ($_POST['email'] ?? ''));
$countryCode = sanitizeLine((string) ($_POST['country_code'] ?? ''));
$phone = sanitizeLine((string) ($_POST['phone'] ?? $_POST['contactNo'] ?? ''));
$country = sanitizeLine((string) ($_POST['country'] ?? ''));
$pageName = sanitizeLine((string) ($_POST['page_name'] ?? 'index_4.html'));
$formLabel = sanitizeLine((string) ($_POST['form_label'] ?? 'Inner Circle Membership'));
$terms = sanitizeLine((string) ($_POST['terms'] ?? ''));

if ($name === '' || $email === '' || $phone === '') {
    respond(422, [
        'success' => false,
        'message' => 'Por favor completa todos los campos requeridos.',
    ]);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(422, [
        'success' => false,
        'message' => 'Por favor introduce un correo electrónico válido.',
    ]);
}

if ($terms === '' || strtolower($terms) === 'not agreed') {
    respond(422, [
        'success' => false,
        'message' => 'Debes aceptar los términos y políticas para registrarte.',
    ]);
}

$phoneCombined = trim($countryCode . ' ' . $phone);

// 1. Guardar registro en MySQL (tabla memberships)
try {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare("
        INSERT INTO `memberships` (
            `full_name`,
            `email`,
            `phone`,
            `country`,
            `source_page`,
            `status`
        ) VALUES (
            :full_name,
            :email,
            :phone,
            :country,
            :source_page,
            'active'
        )
    ");
    $stmt->execute([
        ':full_name'   => $name,
        ':email'       => $email,
        ':phone'       => $phoneCombined,
        ':country'     => $country !== '' ? $country : 'Not Specified',
        ':source_page' => $pageName,
    ]);
} catch (Throwable $e) {
    // Si la BD falla, registramos el error y avisamos
    respond(500, [
        'success' => false,
        'message' => 'Error al guardar en la base de datos MySQL.',
        'error_detail' => $e->getMessage(),
    ]);
}

// 2. Intentar envío de correo como notificación secundaria (sin bloquear si no hay sendmail configurado)
$recipientEmail = 'hello@ombara.com';
$subject = 'Nueva Solicitud: ' . $formLabel . ' - ' . $pageName;
$message = "Nombre: $name\nEmail: $email\nTeléfono: $phoneCombined\nPaís: $country\nPágina: $pageName\nFecha: " . date('Y-m-d H:i:s');
$headers = "From: noreply@ombara.com\r\nReply-To: $email\r\nX-Mailer: PHP/" . phpversion();

@mail($recipientEmail, $subject, $message, $headers);

respond(200, [
    'success' => true,
    'message' => '¡Registro exitoso! Tus datos han sido guardados en nuestra base de datos. Bienvenido al Inner Circle de Ombara.',
]);
