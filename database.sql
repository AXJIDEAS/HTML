-- ========================================================
-- Hotel Ombara - Database Schema (MySQL / MariaDB)
-- Base de Datos para Sistema de Reservas y Membresías
-- ========================================================

CREATE DATABASE IF NOT EXISTS `hotel_ombara` 
CHARACTER SET utf8mb4 
COLLATE utf8mb4_unicode_ci;

USE `hotel_ombara`;

-- --------------------------------------------------------
-- Tabla 1: Reservas de Hotel (reservations)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `reservations` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `booking_code` VARCHAR(30) NOT NULL UNIQUE COMMENT 'Localizador único de reserva (ej: OMB-2026-X82A)',
    `guest_name` VARCHAR(150) NOT NULL,
    `guest_email` VARCHAR(150) NOT NULL,
    `guest_phone` VARCHAR(50) NOT NULL,
    `country` VARCHAR(100) DEFAULT 'Not Specified',
    `room_type` VARCHAR(100) NOT NULL COMMENT 'Tipo de habitación seleccionada',
    `room_rate` DECIMAL(10, 2) NOT NULL DEFAULT 0.00 COMMENT 'Precio por noche en USD',
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

-- --------------------------------------------------------
-- Tabla 2: Membresías / Registros (memberships)
-- Usada para el formulario "Inner Circle" de index_4.html
-- --------------------------------------------------------
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

-- --------------------------------------------------------
-- Datos de Prueba para Demostración
-- --------------------------------------------------------
INSERT INTO `reservations` 
(`booking_code`, `guest_name`, `guest_email`, `guest_phone`, `country`, `room_type`, `room_rate`, `check_in`, `check_out`, `adults`, `children`, `total_nights`, `total_price`, `special_requests`, `status`) 
VALUES
('OMB-2026-A101', 'Alejandro Morales', 'alejandro@example.com', '+52 55 1234 5678', 'Mexico', 'Ocean View Suite', 220.00, CURDATE() + INTERVAL 5 DAY, CURDATE() + INTERVAL 9 DAY, 2, 0, 4, 880.00, 'Piso alto con vista al atardecer', 'confirmed'),
('OMB-2026-B202', 'Sofia Valenzuela', 'sofia@example.com', '+34 612 345 678', 'Spain', 'Garden Villa', 180.00, CURDATE() + INTERVAL 12 DAY, CURDATE() + INTERVAL 15 DAY, 2, 1, 3, 540.00, 'Cuna para bebé requerida', 'confirmed')
ON DUPLICATE KEY UPDATE `id`=`id`;
