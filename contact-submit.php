<?php
declare(strict_types=1);

/**
 * ========================================================
 * Hotel Ombara - Contact & Inquiry Submission Gateway
 * ========================================================
 * Enrutador de compatibilidad hacia backend/controllers/ContactController.php
 */

require_once __DIR__ . '/backend/controllers/ContactController.php';

ContactController::handle();
