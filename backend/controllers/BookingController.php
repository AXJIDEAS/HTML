<?php
declare(strict_types=1);

/**
 * ========================================================
 * Hotel Ombara - Booking Controller
 * ========================================================
 * Archivo: backend/controllers/BookingController.php
 * Procesa peticiones de reserva, valida reglas de negocio,
 * calcula precios, guarda la reserva en MySQL y devuelve JSON.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/ReservationNotifier.php';

class BookingController
{
    private static array $roomCatalog = [
        'Ocean View Suite'        => 220.00,
        'Garden Villa'            => 180.00,
        'Family Residence'        => 310.00,
        'Deluxe King Residence'   => 260.00,
        'Suite Vista al Océano'   => 220.00,
        'Villa Privada Jardín'    => 180.00,
        'Villa Jardín'            => 180.00,
        'Residencia Deluxe King'  => 260.00,
        'Residencia Familiar'     => 310.00,
    ];

    public static function handle(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        ini_set('display_errors', '0');

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            self::respond(405, [
                'success' => false,
                'message' => 'Método no permitido. Se requiere petición POST.',
            ]);
        }

        // Sanitización de entradas
        $name            = self::sanitize((string) ($_POST['name'] ?? $_POST['fullName'] ?? ''));
        $email           = trim((string) ($_POST['email'] ?? ''));
        $countryCode     = self::sanitize((string) ($_POST['country_code'] ?? '+34'));
        $phone           = self::sanitize((string) ($_POST['phone'] ?? ''));
        $country         = self::sanitize((string) ($_POST['country'] ?? ''));
        $roomType        = self::sanitize((string) ($_POST['room_type'] ?? ''));
        $paymentMethod   = self::sanitize((string) ($_POST['payment_method'] ?? ''));
        $checkInStr      = trim((string) ($_POST['check_in'] ?? ''));
        $checkOutStr     = trim((string) ($_POST['check_out'] ?? ''));
        $adults          = max(1, (int) ($_POST['adults'] ?? 1));
        $children        = max(0, (int) ($_POST['children'] ?? 0));
        $specialRequests = trim((string) ($_POST['special_requests'] ?? ''));
        $terms           = self::sanitize((string) ($_POST['terms'] ?? ''));

        // Validaciones obligatorias
        if ($name === '' || $email === '' || $phone === '') {
            self::respond(422, [
                'success' => false,
                'message' => 'Por favor complete su nombre, correo electrónico y número de teléfono.',
            ]);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            self::respond(422, [
                'success' => false,
                'message' => 'El formato del correo electrónico no es válido.',
            ]);
        }

        if ($terms === '' || strtolower($terms) === 'not agreed') {
            self::respond(422, [
                'success' => false,
                'message' => 'Debe aceptar las políticas y términos del hotel para continuar.',
            ]);
        }

        $paymentMethods = ['cash', 'debit_card', 'credit_card', 'bank_transfer', 'online'];
        if (!in_array($paymentMethod, $paymentMethods, true)) {
            self::respond(422, [
                'success' => false,
                'message' => 'Seleccione un método de pago válido.',
            ]);
        }

        // Tarifa de habitación
        if (!array_key_exists($roomType, self::$roomCatalog)) {
            $roomType = 'Ocean View Suite';
        }
        $roomRate = self::$roomCatalog[$roomType];

        // Validación de fechas
        if ($checkInStr === '' || $checkOutStr === '') {
            self::respond(422, [
                'success' => false,
                'message' => 'Por favor seleccione la fecha de llegada y la fecha de salida.',
            ]);
        }

        $checkIn  = DateTime::createFromFormat('Y-m-d', $checkInStr);
        $checkOut = DateTime::createFromFormat('Y-m-d', $checkOutStr);

        if (!$checkIn || !$checkOut) {
            self::respond(422, [
                'success' => false,
                'message' => 'Formato de fecha inválido. Utilice el formato AAAA-MM-DD.',
            ]);
        }

        $today = new DateTime('today');
        $checkIn->setTime(0, 0, 0);
        $checkOut->setTime(0, 0, 0);

        if ($checkIn < $today) {
            self::respond(422, [
                'success' => false,
                'message' => 'La fecha de llegada no puede ser anterior al día de hoy.',
            ]);
        }

        if ($checkOut <= $checkIn) {
            self::respond(422, [
                'success' => false,
                'message' => 'La fecha de salida debe ser posterior a la fecha de llegada (mínimo 1 noche).',
            ]);
        }

        $interval    = $checkIn->diff($checkOut);
        $totalNights = max(1, (int) $interval->days);
        $totalPrice  = round($totalNights * $roomRate, 2);

        // Generar código de reserva único (PNR)
        $bookingCode = 'OMB-2026-' . strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));
        $phoneFull   = trim($countryCode . ' ' . $phone);

        // Assign inventory and persist atomically so concurrent bookings cannot double-allocate a room.
        $pdo = null;
        try {
            $pdo = getDbConnection();
            $pdo->beginTransaction();
            self::reconcileUnassignedReservations($pdo);

            $roomTypes = self::roomTypeVariants($roomType);
            $placeholders = [];
            $roomParams = [];
            foreach ($roomTypes as $index => $type) {
                $placeholder = ':room_type_' . $index;
                $placeholders[] = $placeholder;
                $roomParams[$placeholder] = $type;
            }
            $availableRooms = $pdo->prepare('SELECT `id`, `room_number` FROM `rooms` WHERE `status` = \'available\' AND `room_type` IN (' . implode(', ', $placeholders) . ') ORDER BY `room_number` FOR UPDATE');
            $availableRooms->execute($roomParams);
            $room = null;
            foreach ($availableRooms->fetchAll() as $candidate) {
                $legacyTypePlaceholders = [];
                $conflictParams = [
                    ':room_id' => $candidate['id'],
                    ':check_in' => $checkIn->format('Y-m-d'),
                    ':check_out' => $checkOut->format('Y-m-d'),
                ];
                foreach ($roomTypes as $index => $type) {
                    $placeholder = ':legacy_type_' . $index;
                    $legacyTypePlaceholders[] = $placeholder;
                    $conflictParams[$placeholder] = $type;
                }
                $conflict = $pdo->prepare("SELECT `id` FROM `reservations` WHERE `status` <> 'cancelled' AND `check_in` < :check_out AND `check_out` > :check_in AND (`room_id` = :room_id OR (`room_id` IS NULL AND `room_type` IN (" . implode(', ', $legacyTypePlaceholders) . "))) LIMIT 1 FOR UPDATE");
                $conflict->execute($conflictParams);
                if (!$conflict->fetch()) {
                    $room = $candidate;
                    break;
                }
            }

            if ($room === null) {
                $pdo->rollBack();
                self::respond(409, [
                    'success' => false,
                    'message' => 'No hay habitaciones disponibles de esta categoría para las fechas seleccionadas. Contacte con recepción.',
                ]);
            }

            $stmt = $pdo->prepare("
                INSERT INTO `reservations` (
                    `booking_code`, `guest_name`, `guest_email`, `guest_phone`, `country`,
                    `room_type`, `room_id`, `room_rate`, `check_in`, `check_out`, `adults`, `children`,
                    `total_nights`, `total_price`, `payment_method`, `special_requests`, `status`
                ) VALUES (
                    :booking_code, :guest_name, :guest_email, :guest_phone, :country,
                    :room_type, :room_id, :room_rate, :check_in, :check_out, :adults, :children,
                    :total_nights, :total_price, :payment_method, :special_requests, 'confirmed'
                )
            ");

            $stmt->execute([
                ':booking_code'     => $bookingCode,
                ':guest_name'       => $name,
                ':guest_email'      => $email,
                ':guest_phone'      => $phoneFull,
                ':country'          => $country !== '' ? $country : 'Not Specified',
                ':room_type'        => $roomType,
                ':room_id'          => $room['id'],
                ':room_rate'        => $roomRate,
                ':check_in'         => $checkIn->format('Y-m-d'),
                ':check_out'        => $checkOut->format('Y-m-d'),
                ':adults'           => $adults,
                ':children'         => $children,
                ':total_nights'     => $totalNights,
                ':total_price'      => $totalPrice,
                ':payment_method'   => $paymentMethod,
                ':special_requests' => $specialRequests !== '' ? $specialRequests : null,
            ]);
            $reservationId = (int) $pdo->lastInsertId();
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Database error in BookingController: ' . $e->getMessage());
            self::respond(500, [
                'success' => false,
                'message' => 'No se pudo registrar la reserva. Inténtelo de nuevo o contacte con recepción.',
            ]);
        }

        $booking = [
            'booking_code' => $bookingCode,
            'guest_name' => $name,
            'guest_email' => $email,
            'guest_phone' => $phoneFull,
            'room_type' => $roomType,
            'room_number' => $room['room_number'],
            'check_in' => $checkIn->format('Y-m-d'),
            'check_out' => $checkOut->format('Y-m-d'),
            'total_price' => number_format($totalPrice, 2, '.', ''),
        ];
        try {
            ReservationNotifier::send($pdo, $reservationId, $booking);
        } catch (Throwable $e) {
            error_log('Reservation notification dispatch failed: ' . $e->getMessage());
        }

        self::respond(200, [
            'success' => true,
            'message' => '¡Su reservación ha sido confirmada con éxito!',
            'booking' => [
                'booking_code' => $bookingCode,
                'guest_name'   => $name,
                'guest_email'  => $email,
                'room_type'    => $roomType,
                'room_number'  => $room['room_number'],
                'check_in'     => $checkIn->format('Y-m-d'),
                'check_out'    => $checkOut->format('Y-m-d'),
                'nights'       => $totalNights,
                'total_price'  => number_format($totalPrice, 2, '.', ''),
                'payment_method' => $paymentMethod,
                'status'       => 'confirmed',
            ],
        ]);
    }

    private static function sanitize(string $val): string
    {
        return trim((string) preg_replace("/[\r\n]+/", ' ', $val));
    }

    private static function roomTypeVariants(string $roomType): array
    {
        $variants = [
            ['Ocean View Suite', 'Suite Vista al Océano'],
            ['Garden Villa', 'Villa Privada Jardín', 'Villa Jardín'],
            ['Deluxe King Residence', 'Residencia Deluxe King'],
            ['Family Residence', 'Residencia Familiar'],
        ];
        foreach ($variants as $group) {
            if (in_array($roomType, $group, true)) {
                return $group;
            }
        }

        return [$roomType];
    }

    private static function reconcileUnassignedReservations(PDO $pdo): void
    {
        $unassigned = $pdo->query("SELECT `id`, `room_type`, `check_in`, `check_out` FROM `reservations` WHERE `room_id` IS NULL AND `status` <> 'cancelled' AND `check_out` > CURDATE() ORDER BY `check_in`, `id` FOR UPDATE")->fetchAll();
        if (!$unassigned) {
            return;
        }

        $rooms = $pdo->query("SELECT `id`, `room_type` FROM `rooms` WHERE `status` = 'available' ORDER BY `room_number` FOR UPDATE")->fetchAll();
        foreach ($unassigned as $reservation) {
            $variants = self::roomTypeVariants((string) $reservation['room_type']);
            foreach ($rooms as $room) {
                if (!in_array($room['room_type'], $variants, true)) {
                    continue;
                }

                $conflict = $pdo->prepare("SELECT `id` FROM `reservations` WHERE `room_id` = :room_id AND `status` <> 'cancelled' AND `check_in` < :check_out AND `check_out` > :check_in LIMIT 1 FOR UPDATE");
                $conflict->execute([
                    ':room_id' => $room['id'],
                    ':check_in' => $reservation['check_in'],
                    ':check_out' => $reservation['check_out'],
                ]);
                if (!$conflict->fetch()) {
                    $assign = $pdo->prepare('UPDATE `reservations` SET `room_id` = :room_id WHERE `id` = :id AND `room_id` IS NULL');
                    $assign->execute([':room_id' => $room['id'], ':id' => $reservation['id']]);
                    break;
                }
            }
        }
    }

    private static function respond(int $statusCode, array $payload): void
    {
        http_response_code($statusCode);
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}
