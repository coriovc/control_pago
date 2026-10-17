-- STREAMING_CHUNK:Configuring database schema and character set...
-- ============================================================================
-- SISTEMA DE GESTIÓN DE AGENCIA DIGITAL - ESQUEMA MYSQL (UTF8MB4)
-- Compatible con MySQL 5.7+ / MariaDB 10.3+ (XAMPP / Laragon / Cloud)
-- ============================================================================

CREATE DATABASE IF NOT EXISTS `agencia_liquid_db`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `agencia_liquid_db`;

-- STREAMING_CHUNK:Defining settings table for exchange rate persistence...
-- ----------------------------------------------------------------------------
-- 1. TABLA DE CONFIGURACIÓN GENERAL (Settings & Exchange Rate)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `settings` (
  `setting_key` VARCHAR(64) NOT NULL PRIMARY KEY,
  `setting_value` TEXT NOT NULL,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES
('exchange_rate_ves', '45.80'),
('exchange_rate_mode', 'auto'),
('agency_name', 'LiquidGlass Studio')
ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`);

-- STREAMING_CHUNK:Creating brands table for client retainers...
-- ----------------------------------------------------------------------------
-- 2. TABLA DE MARCAS / CLIENTES (Brands)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `brands` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(120) NOT NULL,
  `category` VARCHAR(80) DEFAULT 'Marketing Digital',
  `start_date` DATE NOT NULL,
  `monthly_fee_usd` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
  `tracking_mode` ENUM('libre', 'limite') NOT NULL DEFAULT 'limite',
  `status` ENUM('activa', 'pausada') NOT NULL DEFAULT 'activa',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- STREAMING_CHUNK:Creating deliverables table with foreign keys...
-- ----------------------------------------------------------------------------
-- 3. TABLA DE ENTREGABLES MENSUALES (Deliverables per Brand & Month)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `deliverables` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `brand_id` INT UNSIGNED NOT NULL,
  `month_year` CHAR(7) NOT NULL COMMENT 'Formato YYYY-MM, ej: 2025-05',
  `flyers_done` INT UNSIGNED NOT NULL DEFAULT 0,
  `flyers_Quota` INT UNSIGNED NOT NULL DEFAULT 12,
  `videos_done` INT UNSIGNED NOT NULL DEFAULT 0,
  `videos_quota` INT UNSIGNED NOT NULL DEFAULT 8,
  `stories_done` INT UNSIGNED NOT NULL DEFAULT 0,
  `stories_quota` INT UNSIGNED NOT NULL DEFAULT 15,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_brand_month` (`brand_id`, `month_year`),
  CONSTRAINT `fk_deliverables_brand`
    FOREIGN KEY (`brand_id`) REFERENCES `brands` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- STREAMING_CHUNK:Creating payments table for bi-weekly tracking...
-- ----------------------------------------------------------------------------
-- 4. TABLA DE PAGOS Y CAPTURES (Bi-Weekly Payments & Receipts)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `payments` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `brand_id` INT UNSIGNED NOT NULL,
  `period_type` ENUM('Q1', 'Q2', 'ABONO') NOT NULL DEFAULT 'Q1',
  `month_year` CHAR(7) NOT NULL COMMENT 'Formato YYYY-MM del periodo cobrado',
  `payment_date` DATE NOT NULL,
  `amount_ves` DECIMAL(14, 2) NOT NULL,
  `exchange_rate` DECIMAL(10, 4) NOT NULL,
  `amount_usd` DECIMAL(10, 2) NOT NULL,
  `reference_code` VARCHAR(80) DEFAULT NULL,
  `notes` VARCHAR(255) DEFAULT NULL,
  `receipt_path` LONGTEXT DEFAULT NULL COMMENT 'Ruta en /uploads o DataURL',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_payment_brand_month` (`brand_id`, `month_year`),
  CONSTRAINT `fk_payments_brand`
    FOREIGN KEY (`brand_id`) REFERENCES `brands` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- STREAMING_CHUNK:Seeding sample initial data for testing...
-- ----------------------------------------------------------------------------
-- 5. DATOS SEMILLA INICIALES (Seed Data)
-- ----------------------------------------------------------------------------
INSERT INTO `brands` (`id`, `name`, `category`, `start_date`, `monthly_fee_usd`, `tracking_mode`, `status`) VALUES
(1, 'Café Ávila Gourmet', 'Gastronomía & Specialty Coffee', '2025-01-10', 480.00, 'limite', 'activa'),
(2, 'Clínica Dental Sonrisa', 'Salud & Odontología Estética', '2025-02-01', 650.00, 'limite', 'activa'),
(3, 'Inmobiliaria Costa Azul', 'Bienes Raíces Luxury', '2025-01-15', 800.00, 'libre', 'activa'),
(4, 'TechNova Store VE', 'E-Commerce & Electrónica', '2025-03-01', 420.00, 'limite', 'activa');

INSERT INTO `deliverables` (`brand_id`, `month_year`, `flyers_done`, `flyers_Quota`, `videos_done`, `videos_quota`, `stories_done`, `stories_quota`) VALUES
(1, DATE_FORMAT(NOW(), '%Y-%m'), 9, 12, 6, 8, 14, 20),
(2, DATE_FORMAT(NOW(), '%Y-%m'), 12, 12, 5, 6, 15, 15),
(3, DATE_FORMAT(NOW(), '%Y-%m'), 16, 0, 11, 0, 28, 0),
(4, DATE_FORMAT(NOW(), '%Y-%m'), 5, 10, 2, 6, 8, 12);

INSERT INTO `payments` (`brand_id`, `period_type`, `month_year`, `payment_date`, `amount_ves`, `exchange_rate`, `amount_usd`, `reference_code`, `notes`) VALUES
(1, 'Q1', DATE_FORMAT(NOW(), '%Y-%m'), DATE_FORMAT(NOW(), '%Y-%m-03'), 10992.00, 45.8000, 240.00, 'PM-884120', 'Pago puntual primera quincena Pago Móvil Banesco'),
(2, 'Q1', DATE_FORMAT(NOW(), '%Y-%m'), DATE_FORMAT(NOW(), '%Y-%m-04'), 14885.00, 45.8000, 325.00, 'TRF-992011', 'Transferencia Mercantil Q1 completa'),
(2, 'Q2', DATE_FORMAT(NOW(), '%Y-%m'), DATE_FORMAT(NOW(), '%Y-%m-17'), 14885.00, 45.8000, 325.00, 'TRF-998450', 'Segunda quincena cancelada en fecha'),
(3, 'Q1', DATE_FORMAT(NOW(), '%Y-%m'), DATE_FORMAT(NOW(), '%Y-%m-05'), 11450.00, 45.8000, 250.00, 'BIN-773190', 'Abono parcial de primera quincena');