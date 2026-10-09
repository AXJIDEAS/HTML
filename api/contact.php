<?php
declare(strict_types=1);

/**
 * Endpoint de API REST para Contacto y Membresías
 * POST /api/contact.php
 */

require_once __DIR__ . '/../backend/controllers/ContactController.php';

ContactController::handle();
