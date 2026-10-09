<?php
declare(strict_types=1);

/**
 * ========================================================
 * Hotel Ombara - Contact & Inquiry Controller
 * ========================================================
 * Archivo: backend/controllers/ContactController.php
 * Procesa peticiones de contacto y registro de membresía,
 * valida los campos y persiste la información en MySQL.
 */

require_once __DIR__ . '/../config/database.php';

class ContactController
{
    public static function handle(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        ini_set('display_errors', '0');

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            self::respond(405, [
                'success' => false,
                'message' => 'Método de petición inválido. Se requiere POST.',
            ]);
        }

        $name        = self::sanitize((string) ($_POST['name'] ?? $_POST['fullName'] ?? ''));
        $email       = trim((string) ($_POST['email'] ?? ''));
        $countryCode = self::sanitize((string) ($_POST['country_code'] ?? ''));
        $phone       = self::sanitize((string) ($_POST['phone'] ?? $_POST['contactNo'] ?? ''));
        $country     = self::sanitize((string) ($_POST['country'] ?? ''));
        $pageName    = self::sanitize((string) ($_POST['page_name'] ?? 'index.html'));
        $terms       = self::sanitize((string) ($_POST['terms'] ?? ''));

        if ($name === '' || $email === '' || $phone === '') {
            self::respond(422, [
                'success' => false,
                'message' => 'Por favor complete todos los campos obligatorios.',
            ]);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            self::respond(422, [
                'success' => false,
                'message' => 'Por favor introduzca un correo electrónico válido.',
            ]);
        }

        if ($terms === '' || strtolower($terms) === 'not agreed') {
            self::respond(422, [
                'success' => false,
                'message' => 'Debe aceptar los términos y políticas para enviar su solicitud.',
            ]);
        }

        $phoneFull = trim($countryCode . ' ' . $phone);

        try {
            $pdo  = getDbConnection();
            $stmt = $pdo->prepare("
                INSERT INTO `memberships` (
                    `full_name`, `email`, `phone`, `country`, `source_page`, `status`
                ) VALUES (
                    :full_name, :email, :phone, :country, :source_page, 'active'
                )
            ");
            $stmt->execute([
                ':full_name'   => $name,
                ':email'       => $email,
                ':phone'       => $phoneFull,
                ':country'     => $country !== '' ? $country : 'Not Specified',
                ':source_page' => $pageName,
            ]);
        } catch (Throwable $e) {
            error_log('Database error in ContactController: ' . $e->getMessage());
        }

        self::respond(200, [
            'success' => true,
            'message' => '¡Gracias! Su solicitud ha sido enviada exitosamente. Nuestro equipo se pondrá en contacto pronto.',
        ]);
    }

    private static function sanitize(string $val): string
    {
        return trim((string) preg_replace("/[\r\n]+/", ' ', $val));
    }

    private static function respond(int $statusCode, array $payload): void
    {
        http_response_code($statusCode);
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}
