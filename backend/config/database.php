<?php
declare(strict_types=1);

/**
 * ========================================================
 * Hotel Ombara - Centralized Database Configuration (MySQL / PDO)
 * ========================================================
 * Archivo: backend/config/database.php
 * Provee conexión PDO persistente, detección automática de entorno
 * y creación dinámica de tablas si no existen.
 */

// Parámetros de conexión a MySQL / MariaDB
if (!defined('DB_HOST')) define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
if (!defined('DB_PORT')) define('DB_PORT', getenv('DB_PORT') ?: '3306');
if (!defined('DB_NAME')) define('DB_NAME', getenv('DB_NAME') ?: 'hotel_ombara');
if (!defined('DB_USER')) define('DB_USER', getenv('DB_USER') ?: 'root');
if (!defined('DB_PASS')) {
    $dbPassword = getenv('DB_PASS');
    if ($dbPassword === false) {
        $requestHost = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        $requestHost = preg_replace('/:\d+$/', '', $requestHost);
        $isLocalTestHost = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)
            && preg_match('/\.(?:localhost|local|test)$/', $requestHost) === 1;
        $dbPassword = $isLocalTestHost ? 'root' : '';
    }
    define('DB_PASS', $dbPassword);
}
if (!defined('DB_CHARSET')) define('DB_CHARSET', 'utf8mb4');

// Evitar romper respuestas JSON con advertencias de obsolescencia
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

/**
 * Obtiene la instancia singleton de la conexión PDO.
 * Compatible con FlyEnv, XAMPP, Laragon, Docker y servidores Linux.
 *
 * @return PDO
 * @throws PDOException
 */
function getDbConnection(): PDO
{
    static $pdo = null;

    if ($pdo !== null) {
        return $pdo;
    }

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    $passwordsToTry = [DB_PASS];
    $connected = false;
    $lastException = null;

    foreach (array_unique($passwordsToTry) as $pass) {
        try {
            $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', DB_HOST, DB_PORT, DB_NAME, DB_CHARSET);
            $pdo = new PDO($dsn, DB_USER, $pass, $options);
            $connected = true;
            break;
        } catch (PDOException $e) {
            $lastException = $e;
            // Si la base de datos no existe (1049), intentar crearla automáticamente
            if ($e->getCode() == 1049 || str_contains($e->getMessage(), 'Unknown database')) {
                try {
                    $rootDsn = sprintf('mysql:host=%s;port=%s;charset=%s', DB_HOST, DB_PORT, DB_CHARSET);
                    $rootPdo = new PDO($rootDsn, DB_USER, $pass, $options);
                    $rootPdo->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                    $pdo = new PDO($dsn, DB_USER, $pass, $options);
                    $connected = true;
                    initDatabaseSchema($pdo);
                    break;
                } catch (PDOException $createEx) {
                    $lastException = $createEx;
                }
            }
        }
    }

    if (!$connected || $pdo === null) {
        throw new PDOException(
            "No se pudo conectar a MySQL tras probar contraseñas habituales. Error: " .
            ($lastException ? $lastException->getMessage() : 'Error desconocido.')
        );
    }

    initDatabaseSchema($pdo);
    return $pdo;
}

/**
 * Asegura la creación de las tablas si no existen.
 */
function initDatabaseSchema(PDO $pdo): void
{
    static $schemaInitialized = false;
    if ($schemaInitialized) return;

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `reservations` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `booking_code` VARCHAR(30) NOT NULL UNIQUE,
            `guest_name` VARCHAR(150) NOT NULL,
            `guest_email` VARCHAR(150) NOT NULL,
            `guest_phone` VARCHAR(50) NOT NULL,
            `country` VARCHAR(100) DEFAULT 'Not Specified',
            `room_type` VARCHAR(100) NOT NULL,
            `room_rate` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            `check_in` DATE NOT NULL,
            `check_out` DATE NOT NULL,
            `adults` INT NOT NULL DEFAULT 1,
            `children` INT NOT NULL DEFAULT 0,
            `total_nights` INT NOT NULL DEFAULT 1,
            `total_price` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            `special_requests` TEXT NULL,
            `status` ENUM('confirmed', 'pending', 'cancelled') NOT NULL DEFAULT 'confirmed',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_booking_code` (`booking_code`),
            INDEX `idx_dates` (`check_in`, `check_out`),
            INDEX `idx_email` (`guest_email`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    $reservationColumns = $pdo->query("SHOW COLUMNS FROM `reservations`")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('payment_method', $reservationColumns, true)) {
        $pdo->exec("ALTER TABLE `reservations` ADD `payment_method` ENUM('cash', 'debit_card', 'credit_card', 'bank_transfer', 'online') DEFAULT NULL AFTER `total_price`");
    }
    if (!in_array('payment_status', $reservationColumns, true)) {
        $pdo->exec("ALTER TABLE `reservations` ADD `payment_status` ENUM('pending', 'paid') NOT NULL DEFAULT 'pending' AFTER `payment_method`");
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `rooms` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `room_number` VARCHAR(20) NOT NULL UNIQUE,
            `room_type` VARCHAR(100) NOT NULL,
            `status` ENUM('available', 'occupied', 'maintenance', 'cleaning') NOT NULL DEFAULT 'available',
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    $reservationColumns = $pdo->query("SHOW COLUMNS FROM `reservations`")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('room_id', $reservationColumns, true)) {
        $pdo->exec("ALTER TABLE `reservations` ADD `room_id` INT NULL AFTER `room_type`, ADD INDEX `idx_reservation_room_dates` (`room_id`, `check_in`, `check_out`)");
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `users` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(150) NOT NULL,
            `email` VARCHAR(190) NOT NULL UNIQUE,
            `phone` VARCHAR(50) DEFAULT NULL,
            `whatsapp_number` VARCHAR(30) DEFAULT NULL,
            `role` ENUM('admin', 'receptionist') NOT NULL DEFAULT 'receptionist',
            `password_hash` VARCHAR(255) NOT NULL,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_users_role_active` (`role`, `is_active`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `notification_logs` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `reservation_id` INT NOT NULL,
            `channel` ENUM('email', 'whatsapp') NOT NULL,
            `recipient` VARCHAR(190) NOT NULL,
            `delivery_status` ENUM('sent', 'failed', 'skipped') NOT NULL,
            `error_code` VARCHAR(80) DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_notification_reservation` (`reservation_id`, `channel`, `delivery_status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `memberships` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `full_name` VARCHAR(150) NOT NULL,
            `email` VARCHAR(150) NOT NULL,
            `phone` VARCHAR(50) NOT NULL,
            `country` VARCHAR(100) DEFAULT 'Not Specified',
            `source_page` VARCHAR(100) DEFAULT 'index.html',
            `status` ENUM('active', 'pending') NOT NULL DEFAULT 'active',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_member_email` (`email`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    $schemaInitialized = true;
}
