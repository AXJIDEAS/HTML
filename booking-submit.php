<?php
declare(strict_types=1);

/**
 * ========================================================
 * Hotel Ombara - Booking Submission API (booking-submit.php)
 * ========================================================
 * Procesa reservaciones hoteleras, valida reglas de negocio,
 * calcula precios reales y guarda la transacción en MySQL.
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db-config.php';

function respond(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function sanitize(string $val): string
{
    return trim((string) preg_replace("/[\r\n]+/", ' ', $val));
}

// 1. Validar método HTTP
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    respond(405, [
        'success' => false,
        'message' => 'Método no permitido. Se requiere petición POST.',
    ]);
}

// 2. Extraer y sanitizar datos recibidos
$name = sanitize((string) ($_POST['name'] ?? $_POST['fullName'] ?? ''));
$email = trim((string) ($_POST['email'] ?? ''));
$countryCode = sanitize((string) ($_POST['country_code'] ?? '+62'));
$phone = sanitize((string) ($_POST['phone'] ?? ''));
$country = sanitize((string) ($_POST['country'] ?? ''));
$roomType = sanitize((string) ($_POST['room_type'] ?? ''));
$checkInStr = trim((string) ($_POST['check_in'] ?? ''));
$checkOutStr = trim((string) ($_POST['check_out'] ?? ''));
$adults = max(1, (int) ($_POST['adults'] ?? 1));
$children = max(0, (int) ($_POST['children'] ?? 0));
$specialRequests = trim((string) ($_POST['special_requests'] ?? ''));
$terms = sanitize((string) ($_POST['terms'] ?? ''));

// 3. Validaciones básicas de campos obligatorios
if ($name === '' || $email === '' || $phone === '') {
    respond(422, [
        'success' => false,
        'message' => 'Por favor completa tu nombre, correo electrónico y número de teléfono.',
    ]);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(422, [
        'success' => false,
        'message' => 'El formato del correo electrónico no es válido.',
    ]);
}

if ($terms === '' || strtolower($terms) === 'not agreed') {
    respond(422, [
        'success' => false,
        'message' => 'Debes aceptar las políticas y términos del hotel para continuar.',
    ]);
}

// 4. Catálogo oficial de habitaciones y tarifas por noche (en USD)
$roomCatalog = [
    'Ocean View Suite'       => 220.00,
    'Garden Villa'           => 180.00,
    'Family Residence'       => 310.00,
    'Deluxe King Residence'  => 260.00,
];

if (!array_key_exists($roomType, $roomCatalog)) {
    // Si no coincide exactamente, asignamos la opción estándar
    $roomType = 'Ocean View Suite';
}
$roomRate = $roomCatalog[$roomType];

// 5. Validación de Fechas (Check-in y Check-out)
if ($checkInStr === '' || $checkOutStr === '') {
    respond(422, [
        'success' => false,
        'message' => 'Por favor selecciona la fecha de llegada (Check-in) y fecha de salida (Check-out).',
    ]);
}

$checkIn = DateTime::createFromFormat('Y-m-d', $checkInStr);
$checkOut = DateTime::createFromFormat('Y-m-d', $checkOutStr);

if (!$checkIn || !$checkOut) {
    respond(422, [
        'success' => false,
        'message' => 'Formato de fecha inválido. Utilice el formato AAAA-MM-DD.',
    ]);
}

// Normalizar horas a medianoche para comparar solo días
$today = new DateTime('today');
$checkIn->setTime(0, 0, 0);
$checkOut->setTime(0, 0, 0);

if ($checkIn < $today) {
    respond(422, [
        'success' => false,
        'message' => 'La fecha de check-in no puede ser anterior al día de hoy.',
    ]);
}

if ($checkOut <= $checkIn) {
    respond(422, [
        'success' => false,
        'message' => 'La fecha de check-out debe ser posterior a la fecha de check-in (mínimo 1 noche).',
    ]);
}

// 6. Cálculo exacto de noches y precio total
$interval = $checkIn->diff($checkOut);
$totalNights = (int) $interval->days;
if ($totalNights < 1) {
    $totalNights = 1;
}

$totalPrice = round($totalNights * $roomRate, 2);
$fullPhone = trim($countryCode . ' ' . $phone);

// 7. Generar Localizador / PNR de Reserva Único (ej: OMB-2026-9F82A)
$bookingCode = sprintf('OMB-%s-%s', date('Y'), strtoupper(bin2hex(random_bytes(3))));

// 8. Inserción Segura en MySQL mediante PDO Prepared Statements
try {
    $pdo = getDbConnection();

    $stmt = $pdo->prepare("
        INSERT INTO `reservations` (
            `booking_code`,
            `guest_name`,
            `guest_email`,
            `guest_phone`,
            `country`,
            `room_type`,
            `room_rate`,
            `check_in`,
            `check_out`,
            `adults`,
            `children`,
            `total_nights`,
            `total_price`,
            `special_requests`,
            `status`
        ) VALUES (
            :booking_code,
            :guest_name,
            :guest_email,
            :guest_phone,
            :country,
            :room_type,
            :room_rate,
            :check_in,
            :check_out,
            :adults,
            :children,
            :total_nights,
            :total_price,
            :special_requests,
            'confirmed'
        )
    ");

    $stmt->execute([
        ':booking_code'     => $bookingCode,
        ':guest_name'       => $name,
        ':guest_email'      => $email,
        ':guest_phone'      => $fullPhone,
        ':country'          => $country !== '' ? $country : 'Not Specified',
        ':room_type'        => $roomType,
        ':room_rate'        => $roomRate,
        ':check_in'         => $checkIn->format('Y-m-d'),
        ':check_out'        => $checkOut->format('Y-m-d'),
        ':adults'           => $adults,
        ':children'         => $children,
        ':total_nights'     => $totalNights,
        ':total_price'      => $totalPrice,
        ':special_requests' => $specialRequests !== '' ? $specialRequests : null,
    ]);

    $reservationId = (int) $pdo->lastInsertId();

    // 9. Respuesta exitosa con resumen completo para el voucher
    respond(200, [
        'success' => true,
        'message' => '¡Tu reserva ha sido confirmada con éxito!',
        'booking' => [
            'id'               => $reservationId,
            'booking_code'     => $bookingCode,
            'guest_name'       => $name,
            'guest_email'      => $email,
            'guest_phone'      => $fullPhone,
            'room_type'        => $roomType,
            'room_rate'        => $roomRate,
            'check_in'         => $checkIn->format('d M Y'),
            'check_out'        => $checkOut->format('d M Y'),
            'nights'           => $totalNights,
            'adults'           => $adults,
            'children'         => $children,
            'total_price'      => number_format($totalPrice, 2),
            'special_requests' => $specialRequests,
            'status'           => 'confirmed',
            'created_at'       => date('d/m/Y H:i'),
        ],
    ]);

} catch (PDOException $e) {
    // Si hay un error de conexión con MySQL, informarlo claramente
    respond(500, [
        'success' => false,
        'message' => 'Error al conectar con la base de datos MySQL. Verifica las credenciales en db-config.php.',
        'error_detail' => $e->getMessage(),
    ]);
}
