<?php
declare(strict_types=1);

/**
 * Endpoint de API REST para Reservaciones
 * POST /api/booking.php
 */

require_once __DIR__ . '/../backend/controllers/BookingController.php';

BookingController::handle();
