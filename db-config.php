<?php
declare(strict_types=1);

/**
 * ========================================================
 * Hotel Ombara - Database Configuration (MySQL / PDO)
 * ========================================================
 * Configuración centralizada de base de datos MySQL con PDO.
 * Compatible con XAMPP, Laragon, Docker, cPanel y hosting compartido.
 */

// Parámetros de conexión a MySQL / MariaDB
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_PORT', getenv('DB_PORT') ?: '3306');
define('DB_NAME', getenv('DB_NAME') ?: 'hotel_ombara');
define('DB_USER', getenv('DB_USER') ?: 'root');
// En FlyEnv la contraseña predeterminada es 'root', en XAMPP es ''
define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : 'root');
define('DB_CHARSET', 'utf8mb4');

// Silenciar advertencias de obsolescencia para evitar corromper respuestas JSON en PHP 8.5+
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

/**
 * Obtiene la conexión PDO a MySQL.
 * Compatible automáticamente con FlyEnv (Arch Linux) y XAMPP clásico.
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

    // Lista de contraseñas a probar automáticamente (FlyEnv usa 'root', XAMPP usa '')
    $passwordsToTry = [DB_PASS, 'root', ''];
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
            // Si el error es base de datos desconocida (código 1049), intentar crearla
            if ($e->getCode() == 1049 || str_contains($e->getMessage(), 'Unknown database')) {
                try {
                    $rootDsn = sprintf('mysql:host=%s;port=%s;charset=%s', DB_HOST, DB_PORT, DB_CHARSET);
                    $rootPdo = new PDO($rootDsn, DB_USER, $pass, $options);
                    $rootPdo->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                    
                    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', DB_HOST, DB_PORT, DB_NAME, DB_CHARSET);
                    $pdo = new PDO($dsn, DB_USER, $pass, $options);
                    $connected = true;
                    break;
                } catch (PDOException $ex) {
                    $lastException = $ex;
                }
            }
        }
    }

    if (!$connected && $lastException) {
        throw $lastException;
    }

    // Auto-inicializar tablas si no existen
    ensureTablesExist($pdo);

    return $pdo;
}

/**
 * Crea automáticamente las tablas si es la primera vez que se ejecuta.
 */
function ensureTablesExist(PDO $pdo): void
{
    $tableCheck = $pdo->query("SHOW TABLES LIKE 'reservations'");
    if ($tableCheck->rowCount() === 0) {
        // Crear tabla de reservas
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
    }

    $memberTableCheck = $pdo->query("SHOW TABLES LIKE 'memberships'");
    if ($memberTableCheck->rowCount() === 0) {
        // Crear tabla de membresías
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `memberships` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `full_name` VARCHAR(150) NOT NULL,
                `email` VARCHAR(150) NOT NULL,
                `phone` VARCHAR(50) NOT NULL,
                `country` VARCHAR(100) DEFAULT 'Not Specified',
                `source_page` VARCHAR(100) DEFAULT 'index_4.html',
                `status` ENUM('active', 'pending') NOT NULL DEFAULT 'active',
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_member_email` (`email`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    }
}
