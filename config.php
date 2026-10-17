<?php
/**
 * ============================================================================
 * ARCHIVO DE CONFIGURACIÓN DE BASE DE DATOS (MySQL / phpMyAdmin)
 * ============================================================================
 * Compatible con XAMPP, Laragon, WAMP, cPanel, Hostinger y SQLite local.
 * 
 * Si usas XAMPP o Laragon en tu PC:
 * - DB_HOST: '127.0.0.1' o 'localhost'
 * - DB_NAME: 'agencia_liquid_db' (el sistema la crea automáticamente)
 * - DB_USER: 'root'
 * - DB_PASS: '' (vacío en XAMPP por defecto)
 * - DB_PORT: 3306
 *
 * Si no tienes MySQL activo, el sistema usa automáticamente la base de datos
 * SQLite local en este mismo directorio ('agencia_liquid_db.sqlite') para
 * que tus datos NUNCA se pierdan y se guarden en la carpeta.
 */

define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_PORT', (int)(getenv('DB_PORT') ?: 3306));
define('DB_NAME', getenv('DB_NAME') ?: 'agencia_liquid_db');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : '');
define('DB_CHARSET', 'utf8mb4');

// Zona horaria (Caracas / VET)
date_default_timezone_set('America/Caracas');
