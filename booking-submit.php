<?php
declare(strict_types=1);

/**
 * ========================================================
 * Hotel Ombara - Booking Submission Gateway
 * ========================================================
 * Enrutador de compatibilidad hacia backend/controllers/BookingController.php
 */

require_once __DIR__ . '/backend/controllers/BookingController.php';

BookingController::handle();
