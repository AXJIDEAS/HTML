<?php
declare(strict_types=1);

class ReservationNotifier
{
    public static function send(PDO $pdo, int $reservationId, array $booking): void
    {
        $staff = $pdo->query("SELECT `email`, `whatsapp_number` FROM `users` WHERE `is_active` = 1 AND `role` IN ('admin', 'receptionist')")->fetchAll();
        $message = self::message($booking);
        $emails = array_unique(array_filter(
            array_merge([$booking['guest_email']], array_column($staff, 'email')),
            static fn ($email): bool => is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) !== false
        ));

        foreach ($emails as $email) {
            $from = getenv('MAIL_FROM') ?: '';
            if ($from === '' || !function_exists('mail')) {
                self::record($pdo, $reservationId, 'email', $email, 'skipped', 'mail_not_configured');
                continue;
            }

            $subject = 'Comprobante de reserva ' . $booking['booking_code'];
            $headers = "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nFrom: " . $from;
            $sent = @mail($email, $subject, $message, $headers);
            self::record($pdo, $reservationId, 'email', $email, $sent ? 'sent' : 'failed', $sent ? null : 'mail_transport_failed');
        }

        $whatsappNumbers = array_unique(array_filter(array_merge([$booking['guest_phone']], array_column($staff, 'whatsapp_number'))));
        foreach ($whatsappNumbers as $number) {
            $recipient = self::normalizePhone((string) $number);
            if ($recipient === null) {
                self::record($pdo, $reservationId, 'whatsapp', substr((string) $number, 0, 190), 'skipped', 'invalid_phone');
                continue;
            }

            $status = self::sendWhatsApp($recipient, $booking, $errorCode);
            self::record($pdo, $reservationId, 'whatsapp', $recipient, $status, $errorCode);
        }
    }

    private static function message(array $booking): string
    {
        return implode("\n", [
            'Comprobante de reserva Ombara',
            'Código: ' . $booking['booking_code'],
            'Huésped: ' . $booking['guest_name'],
            'Habitación: ' . $booking['room_type'] . ' - ' . $booking['room_number'],
            'Llegada: ' . $booking['check_in'],
            'Salida: ' . $booking['check_out'],
            'Total estimado: $' . $booking['total_price'] . ' USD',
        ]);
    }

    private static function sendWhatsApp(string $recipient, array $booking, ?string &$errorCode): string
    {
        $phoneNumberId = getenv('WHATSAPP_PHONE_NUMBER_ID') ?: '';
        $accessToken = getenv('WHATSAPP_ACCESS_TOKEN') ?: '';
        if ($phoneNumberId === '' || $accessToken === '') {
            $errorCode = 'whatsapp_not_configured';
            return 'skipped';
        }
        $template = getenv('WHATSAPP_TEMPLATE_NAME') ?: 'reservation_confirmation';
        $version = getenv('WHATSAPP_GRAPH_VERSION') ?: 'v21.0';
        $parameters = [
            $booking['booking_code'], $booking['guest_name'], $booking['room_type'],
            $booking['room_number'], $booking['check_in'], $booking['check_out'],
            (string) $booking['total_price'],
        ];
        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => ltrim($recipient, '+'),
            'type' => 'template',
            'template' => [
                'name' => $template,
                'language' => ['code' => 'es'],
                'components' => [[
                    'type' => 'body',
                    'parameters' => array_map(static fn (string $value): array => ['type' => 'text', 'text' => $value], $parameters),
                ]],
            ],
        ];
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Authorization: Bearer " . $accessToken . "\r\nContent-Type: application/json\r\n",
            'content' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'timeout' => 8,
            'ignore_errors' => true,
        ]]);
        $response = @file_get_contents('https://graph.facebook.com/' . rawurlencode($version) . '/' . rawurlencode($phoneNumberId) . '/messages', false, $context);
        $httpStatus = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $matches)) {
                $httpStatus = (int) $matches[1];
            }
        }
        if ($response !== false && $httpStatus >= 200 && $httpStatus < 300) {
            $errorCode = null;
            return 'sent';
        }
        $errorCode = $httpStatus > 0 ? 'whatsapp_http_' . $httpStatus : 'whatsapp_request_failed';
        return 'failed';
    }

    private static function normalizePhone(string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', $phone);
        return is_string($digits) && preg_match('/^[1-9][0-9]{7,14}$/', $digits) ? '+' . $digits : null;
    }

    private static function record(PDO $pdo, int $reservationId, string $channel, string $recipient, string $status, ?string $errorCode): void
    {
        try {
            $statement = $pdo->prepare('INSERT INTO `notification_logs` (`reservation_id`, `channel`, `recipient`, `delivery_status`, `error_code`) VALUES (:reservation_id, :channel, :recipient, :status, :error_code)');
            $statement->execute([
                ':reservation_id' => $reservationId,
                ':channel' => $channel,
                ':recipient' => $recipient,
                ':status' => $status,
                ':error_code' => $errorCode,
            ]);
        } catch (Throwable $exception) {
            error_log('Could not record reservation notification status: ' . $exception->getMessage());
        }
    }
}
