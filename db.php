<?php
/**
 * ============================================================================
 * CONEXIÓN PDO INTELIGENTE CON AUTO-INICIALIZACIÓN DE LA BASE DE DATOS
 * ============================================================================
 * 1. Conecta con MySQL según config.php.
 * 2. Si la base de datos 'agencia_liquid_db' no existe, la crea automáticamente.
 * 3. Si las tablas no existen, importa 'esquema_de_base_de_datos_mysql.sql'.
 * 4. Si MySQL no está iniciado, utiliza como respaldo SQLite local en la carpeta
 *    ('agencia_liquid_db.sqlite') para que los datos SIEMPRE se guarden en disco.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

function getDbConnection(): array {
    static $connectionData = null;

    if ($connectionData !== null) {
        return $connectionData;
    }

    $errors = [];

    // INTENTO 1: Conexión a MySQL
    try {
        $host = DB_HOST;
        $port = DB_PORT;
        $dbName = DB_NAME;
        $charset = DB_CHARSET;

        // Conectar primero sin nombre de base de datos por si aún no ha sido creada
        $dsnInitial = "mysql:host={$host};port={$port};charset={$charset}";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => 2, // 2 segundos máximo para no colgar si MySQL está apagado
        ];

        $pdo = new PDO($dsnInitial, DB_USER, DB_PASS, $options);

        // Crear base de datos si no existe
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET {$charset} COLLATE {$charset}_unicode_ci");
        $pdo->exec("USE `{$dbName}`");

        // Verificar si la tabla de marcas ya existe
        $stmtCheck = $pdo->query("SHOW TABLES LIKE 'brands'");
        if ($stmtCheck->rowCount() === 0) {
            initMysqlTables($pdo);
        }

        $connectionData = [
            'pdo'    => $pdo,
            'driver' => 'mysql',
            'source' => "MySQL ({$dbName})"
        ];
        return $connectionData;

    } catch (Throwable $e) {
        $errors['mysql'] = $e->getMessage();
    }

    // INTENTO 2: Fallback a SQLite en la misma carpeta del proyecto
    try {
        $sqliteFile = __DIR__ . DIRECTORY_SEPARATOR . 'agencia_liquid_db.sqlite';
        $isNewFile = !file_exists($sqliteFile);

        $pdoSqlite = new PDO("sqlite:" . $sqliteFile, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdoSqlite->exec("PRAGMA foreign_keys = ON;");

        // Verificar si existen las tablas
        $stmtTable = $pdoSqlite->query("SELECT name FROM sqlite_master WHERE type='table' AND name='brands'");
        if (!$stmtTable->fetch()) {
            initSqliteTables($pdoSqlite);
        }

        $connectionData = [
            'pdo'    => $pdoSqlite,
            'driver' => 'sqlite',
            'source' => 'SQLite local (agencia_liquid_db.sqlite)'
        ];
        return $connectionData;

    } catch (Throwable $e) {
        $errors['sqlite'] = $e->getMessage();
    }

    // Si ambos fallaron
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'error'   => 'No se pudo conectar a MySQL ni inicializar SQLite local en la carpeta.',
        'details' => $errors,
        'hint'    => 'Verifica que MySQL esté corriendo en XAMPP o que la carpeta tenga permisos de escritura.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Inicializa las tablas en MySQL ejecutando el archivo SQL si existe, o DDL directo
 */
function initMysqlTables(PDO $pdo): void {
    $sqlFile = __DIR__ . DIRECTORY_SEPARATOR . 'esquema_de_base_de_datos_mysql.sql';
    if (file_exists($sqlFile)) {
        $sql = file_get_contents($sqlFile);
        if ($sql) {
            // Dividir por punto y coma seguro o ejecutar multi-queries
            $pdo->exec($sql);
            return;
        }
    }

    // Respaldo de DDL si el archivo no estuviese disponible
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `settings` (
            `setting_key` VARCHAR(64) NOT NULL PRIMARY KEY,
            `setting_value` TEXT NOT NULL,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES
        ('exchange_rate_ves', '45.80'),
        ('exchange_rate_mode', 'auto'),
        ('agency_name', 'LiquidGlass Studio');

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

        CREATE TABLE IF NOT EXISTS `deliverables` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `brand_id` INT UNSIGNED NOT NULL,
            `month_year` CHAR(7) NOT NULL,
            `flyers_done` INT UNSIGNED NOT NULL DEFAULT 0,
            `flyers_quota` INT UNSIGNED NOT NULL DEFAULT 12,
            `videos_done` INT UNSIGNED NOT NULL DEFAULT 0,
            `videos_quota` INT UNSIGNED NOT NULL DEFAULT 8,
            `stories_done` INT UNSIGNED NOT NULL DEFAULT 0,
            `stories_quota` INT UNSIGNED NOT NULL DEFAULT 15,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uk_brand_month` (`brand_id`, `month_year`),
            CONSTRAINT `fk_deliverables_brand` FOREIGN KEY (`brand_id`) REFERENCES `brands` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        CREATE TABLE IF NOT EXISTS `payments` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `brand_id` INT UNSIGNED NOT NULL,
            `period_type` ENUM('Q1', 'Q2', 'ABONO') NOT NULL DEFAULT 'Q1',
            `month_year` CHAR(7) NOT NULL,
            `payment_date` DATE NOT NULL,
            `amount_ves` DECIMAL(14, 2) NOT NULL,
            `exchange_rate` DECIMAL(10, 4) NOT NULL,
            `amount_usd` DECIMAL(10, 2) NOT NULL,
            `reference_code` VARCHAR(80) DEFAULT NULL,
            `notes` VARCHAR(255) DEFAULT NULL,
            `receipt_path` LONGTEXT DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_payment_brand_month` (`brand_id`, `month_year`),
            CONSTRAINT `fk_payments_brand` FOREIGN KEY (`brand_id`) REFERENCES `brands` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
}

/**
 * Inicializa tablas en SQLite si MySQL no está disponible
 */
function initSqliteTables(PDO $pdo): void {
    $currentMonth = date('Y-m');
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS settings (
            setting_key TEXT PRIMARY KEY,
            setting_value TEXT NOT NULL,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        INSERT OR IGNORE INTO settings (setting_key, setting_value) VALUES
        ('exchange_rate_ves', '45.80'),
        ('exchange_rate_mode', 'auto'),
        ('agency_name', 'LiquidGlass Studio');

        CREATE TABLE IF NOT EXISTS brands (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            category TEXT DEFAULT 'Marketing Digital',
            start_date TEXT NOT NULL,
            monthly_fee_usd REAL NOT NULL DEFAULT 0.00,
            tracking_mode TEXT NOT NULL DEFAULT 'limite',
            status TEXT NOT NULL DEFAULT 'activa',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS deliverables (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            brand_id INTEGER NOT NULL REFERENCES brands(id) ON DELETE CASCADE,
            month_year TEXT NOT NULL,
            flyers_done INTEGER NOT NULL DEFAULT 0,
            flyers_quota INTEGER NOT NULL DEFAULT 12,
            videos_done INTEGER NOT NULL DEFAULT 0,
            videos_quota INTEGER NOT NULL DEFAULT 8,
            stories_done INTEGER NOT NULL DEFAULT 0,
            stories_quota INTEGER NOT NULL DEFAULT 15,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(brand_id, month_year)
        );

        CREATE TABLE IF NOT EXISTS payments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            brand_id INTEGER NOT NULL REFERENCES brands(id) ON DELETE CASCADE,
            period_type TEXT NOT NULL DEFAULT 'Q1',
            month_year TEXT NOT NULL,
            payment_date TEXT NOT NULL,
            amount_ves REAL NOT NULL,
            exchange_rate REAL NOT NULL,
            amount_usd REAL NOT NULL,
            reference_code TEXT DEFAULT NULL,
            notes TEXT DEFAULT NULL,
            receipt_path TEXT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        INSERT OR IGNORE INTO brands (id, name, category, start_date, monthly_fee_usd, tracking_mode, status) VALUES
        (1, 'Café Ávila Gourmet', 'Gastronomía & Specialty Coffee', '2025-01-10', 480.00, 'limite', 'activa'),
        (2, 'Clínica Dental Sonrisa', 'Salud & Odontología Estética', '2025-02-01', 650.00, 'limite', 'activa'),
        (3, 'Inmobiliaria Costa Azul', 'Bienes Raíces Luxury', '2025-01-15', 800.00, 'libre', 'activa'),
        (4, 'TechNova Store VE', 'E-Commerce & Electrónica', '2025-03-01', 420.00, 'limite', 'activa');

        INSERT OR IGNORE INTO deliverables (brand_id, month_year, flyers_done, flyers_quota, videos_done, videos_quota, stories_done, stories_quota) VALUES
        (1, '{$currentMonth}', 9, 12, 6, 8, 14, 20),
        (2, '{$currentMonth}', 12, 12, 5, 6, 15, 15),
        (3, '{$currentMonth}', 16, 0, 11, 0, 28, 0),
        (4, '{$currentMonth}', 5, 10, 2, 6, 8, 12);

        INSERT OR IGNORE INTO payments (brand_id, period_type, month_year, payment_date, amount_ves, exchange_rate, amount_usd, reference_code, notes) VALUES
        (1, 'Q1', '{$currentMonth}', '{$currentMonth}-03', 10992.00, 45.8000, 240.00, 'PM-884120', 'Pago puntual primera quincena Pago Móvil Banesco'),
        (2, 'Q1', '{$currentMonth}', '{$currentMonth}-04', 14885.00, 45.8000, 325.00, 'TRF-992011', 'Transferencia Mercantil Q1 completa'),
        (2, 'Q2', '{$currentMonth}', '{$currentMonth}-17', 14885.00, 45.8000, 325.00, 'TRF-998450', 'Segunda quincena cancelada en fecha'),
        (3, 'Q1', '{$currentMonth}', '{$currentMonth}-05', 11450.00, 45.8000, 250.00, 'BIN-773190', 'Abono parcial de primera quincena');
    ");
}
