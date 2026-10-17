<?php
/**
 * ============================================================================
 * API RESTFUL BACKEND (PHP 8+ & PDO) - SISTEMA LIQUID GLASS AGENCIA
 * ============================================================================
 * Maneja el CRUD de Marcas, Entregables, Pagos con subida de Captures y Métricas
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Parámetros de conexión MySQL (Ajustar según entorno XAMPP / Laragon / Hosting)
$dbHost = getenv('DB_HOST') ?: '127.0.0.1';
$dbName = getenv('DB_NAME') ?: 'agencia_liquid_db';
$dbUser = getenv('DB_USER') ?: 'root';
$dbPass = getenv('DB_PASS') ?: '';

try {
    $dsn = "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4";
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (PDOException $e) {
    http_response_code(503);
    echo json_encode([
        'success' => false,
        'error'   => 'No se pudo conectar a la base de datos MySQL.',
        'details' => $e->getMessage()
    ]);
    exit;
}

/**
 * Envía respuesta JSON estandarizada
 */
function respond(bool $success, mixed $data = null, ?string $message = null, int $status = 200): void {
    http_response_code($status);
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data'    => $data
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Obtiene el body JSON o variables POST cuando se envía multipart/form-data
 */
function getRequestPayload(): array {
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($contentType, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        $decoded = json_decode($raw ?: '{}', true);
        return is_array($decoded) ? $decoded : [];
    }
    return $_POST;
}

$action = $_GET['action'] ?? ($_POST['action'] ?? 'bootstrap');
$currentMonth = $_GET['month'] ?? date('Y-m');

try {
    switch ($action) {
        // --------------------------------------------------------------------
        // 1. OBTENER ESTADO COMPLETO (Marcas + Entregables + Pagos del Mes)
        // --------------------------------------------------------------------
        case 'bootstrap':
            // Configuraciones
            $stmtSettings = $pdo->query("SELECT setting_key, setting_value FROM settings");
            $settingsRaw = $stmtSettings->fetchAll();
            $settings = [];
            foreach ($settingsRaw as $row) {
                $settings[$row['setting_key']] = $row['setting_value'];
            }

            // Marcas activas
            $stmtBrands = $pdo->query("SELECT * FROM brands WHERE status = 'activa' ORDER BY id DESC");
            $brands = $stmtBrands->fetchAll();

            // Asegurar que cada marca tenga su fila de entregables para el mes actual
            $stmtDelivCheck = $pdo->prepare("
                INSERT IGNORE INTO deliverables (brand_id, month_year, flyers_done, flyers_Quota, videos_done, videos_quota, stories_done, stories_quota)
                VALUES (:brand_id, :month_year, 0, 12, 0, 8, 0, 15)
            ");
            foreach ($brands as $b) {
                $stmtDelivCheck->execute([
                    ':brand_id'   => $b['id'],
                    ':month_year' => $currentMonth
                ]);
            }

            // Consultar entregables del mes
            $stmtDeliv = $pdo->prepare("SELECT * FROM deliverables WHERE month_year = :month_year");
            $stmtDeliv->execute([':month_year' => $currentMonth]);
            $deliverablesList = $stmtDeliv->fetchAll();
            $deliverablesByBrand = [];
            foreach ($deliverablesList as $d) {
                $deliverablesByBrand[(int)$d['brand_id']] = [
                    'flyers_done'   => (int)$d['flyers_done'],
                    'flyers_quota'  => (int)$d['flyers_Quota'],
                    'videos_done'   => (int)$d['videos_done'],
                    'videos_quota'  => (int)$d['videos_quota'],
                    'stories_done'  => (int)$d['stories_done'],
                    'stories_quota' => (int)$d['stories_quota'],
                ];
            }

            // Integrar entregables en cada marca
            foreach ($brands as &$brand) {
                $bid = (int)$brand['id'];
                $brand['id'] = $bid;
                $brand['monthly_fee_usd'] = (float)$brand['monthly_fee_usd'];
                $brand['deliverables'] = $deliverablesByBrand[$bid] ?? [
                    'flyers_done' => 0, 'flyers_quota' => 12,
                    'videos_done' => 0, 'videos_quota' => 8,
                    'stories_done' => 0, 'stories_quota' => 15
                ];
            }

            // Pagos registrados
            $stmtPayments = $pdo->prepare("
                SELECT p.*, b.name AS brand_name
                FROM payments p
                INNER JOIN brands b ON b.id = p.brand_id
                WHERE p.month_year = :month_year
                ORDER BY p.payment_date DESC, p.id DESC
            ");
            $stmtPayments->execute([':month_year' => $currentMonth]);
            $payments = $stmtPayments->fetchAll();
            foreach ($payments as &$pay) {
                $pay['id'] = (int)$pay['id'];
                $pay['brand_id'] = (int)$pay['brand_id'];
                $pay['amount_ves'] = (float)$pay['amount_ves'];
                $pay['exchange_rate'] = (float)$pay['exchange_rate'];
                $pay['amount_usd'] = (float)$pay['amount_usd'];
            }

            respond(true, [
                'month'    => $currentMonth,
                'settings' => $settings,
                'brands'   => $brands,
                'payments' => $payments
            ]);
            break;

        // --------------------------------------------------------------------
        // 2. CREAR O EDITAR MARCA (Con configuración de modo Libre o Con Límite)
        // --------------------------------------------------------------------
        case 'save_brand':
            $payload = getRequestPayload();
            $id            = isset($payload['id']) && $payload['id'] !== '' ? (int)$payload['id'] : null;
            $name          = trim((string)($payload['name'] ?? ''));
            $category      = trim((string)($payload['category'] ?? 'Marketing & Diseño'));
            $startDate     = trim((string)($payload['start_date'] ?? date('Y-m-d')));
            $monthlyFeeUsd = (float)($payload['monthly_fee_usd'] ?? 0);
            $trackingMode  = in_array($payload['tracking_mode'] ?? 'limite', ['libre', 'limite'], true)
                             ? $payload['tracking_mode'] : 'limite';

            $flyersQuota   = max(0, (int)($payload['flyers_quota'] ?? 12));
            $videosQuota   = max(0, (int)($payload['videos_quota'] ?? 8));
            $storiesQuota  = max(0, (int)($payload['stories_quota'] ?? 15));
            $monthYear     = trim((string)($payload['month_year'] ?? date('Y-m')));

            if ($name === '' || $monthlyFeeUsd <= 0) {
                respond(false, null, 'Por favor ingresa un nombre de marca válido y una mensualidad mayor a $0.', 422);
            }

            $pdo->beginTransaction();

            if ($id) {
                $stmt = $pdo->prepare("
                    UPDATE brands
                    SET name = :name, category = :category, start_date = :start_date,
                        monthly_fee_usd = :fee, tracking_mode = :mode
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':name'       => $name,
                    ':category'   => $category,
                    ':start_date' => $startDate,
                    ':fee'        => $monthlyFeeUsd,
                    ':mode'       => $trackingMode,
                    ':id'         => $id
                ]);
                $brandId = $id;
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO brands (name, category, start_date, monthly_fee_usd, tracking_mode, status)
                    VALUES (:name, :category, :start_date, :fee, :mode, 'activa')
                ");
                $stmt->execute([
                    ':name'       => $name,
                    ':category'   => $category,
                    ':start_date' => $startDate,
                    ':fee'        => $monthlyFeeUsd,
                    ':mode'       => $trackingMode
                ]);
                $brandId = (int)$pdo->lastInsertId();
            }

            // Actualizar o insertar cuotas de entregables para el mes
            $stmtDeliv = $pdo->prepare("
                INSERT INTO deliverables (brand_id, month_year, flyers_done, flyers_Quota, videos_done, videos_quota, stories_done, stories_quota)
                VALUES (:brand_id, :month_year, 0, :fq, 0, :vq, 0, :sq)
                ON DUPLICATE KEY UPDATE
                    flyers_Quota = VALUES(flyers_Quota),
                    videos_quota = VALUES(videos_quota),
                    stories_quota = VALUES(stories_quota)
            ");
            $stmtDeliv->execute([
                ':brand_id'   => $brandId,
                ':month_year' => $monthYear,
                ':fq'         => $flyersQuota,
                ':vq'         => $videosQuota,
                ':sq'         => $storiesQuota
            ]);

            $pdo->commit();
            respond(true, ['brand_id' => $brandId], 'Marca guardada exitosamente.');
            break;

        // --------------------------------------------------------------------
        // 3. ACTUALIZAR CONTADOR DE ENTREGABLES (+1 / -1 Asíncrono)
        // --------------------------------------------------------------------
        case 'update_deliverable':
            $payload   = getRequestPayload();
            $brandId   = (int)($payload['brand_id'] ?? 0);
            $monthYear = trim((string)($payload['month_year'] ?? date('Y-m')));
            $type      = $payload['type'] ?? ''; // flyers | videos | stories
            $delta     = (int)($payload['delta'] ?? 0);

            $allowedColumns = [
                'flyers'  => 'flyers_done',
                'videos'  => 'videos_done',
                'stories' => 'stories_done'
            ];

            if ($brandId <= 0 || !isset($allowedColumns[$type]) || $delta === 0) {
                respond(false, null, 'Parámetros de entregable inválidos.', 422);
            }

            $col = $allowedColumns[$type];
            $stmt = $pdo->prepare("
                UPDATE deliverables
                SET {$col} = GREATEST(0, CAST({$col} AS SIGNED) + :delta)
                WHERE brand_id = :brand_id AND month_year = :month_year
            ");
            $stmt->execute([
                ':delta'      => $delta,
                ':brand_id'   => $brandId,
                ':month_year' => $monthYear
            ]);

            $stmtFetch = $pdo->prepare("SELECT * FROM deliverables WHERE brand_id = :brand_id AND month_year = :month_year");
            $stmtFetch->execute([':brand_id' => $brandId, ':month_year' => $monthYear]);
            $updated = $stmtFetch->fetch();

            respond(true, $updated, 'Entregable actualizado.');
            break;

        // --------------------------------------------------------------------
        // 4. REGISTRAR PAGO Y SUBIR CAPTURE A /uploads
        // --------------------------------------------------------------------
        case 'create_payment':
            $payload      = getRequestPayload();
            $brandId      = (int)($payload['brand_id'] ?? 0);
            $periodType   = in_array($payload['period_type'] ?? 'Q1', ['Q1', 'Q2', 'ABONO'], true)
                            ? $payload['period_type'] : 'Q1';
            $monthYear    = trim((string)($payload['month_year'] ?? date('Y-m')));
            $paymentDate  = trim((string)($payload['payment_date'] ?? date('Y-m-d')));
            $amountVes    = (float)($payload['amount_ves'] ?? 0);
            $exchangeRate = (float)($payload['exchange_rate'] ?? 0);
            $reference    = trim((string)($payload['reference_code'] ?? ''));
            $notes        = trim((string)($payload['notes'] ?? ''));
            $receiptPath  = $payload['receipt_base64'] ?? null;

            if ($brandId <= 0 || $amountVes <= 0 || $exchangeRate <= 0) {
                respond(false, null, 'Completa los datos de marca, monto en Bolívares y tasa válida.', 422);
            }

            $amountUsd = round($amountVes / $exchangeRate, 2);

            // Procesar archivo subido vía multipart/form-data si existe
            if (isset($_FILES['receipt_file']) && $_FILES['receipt_file']['error'] === UPLOAD_ERR_OK) {
                $uploadDir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }

                $tmpName = $_FILES['receipt_file']['tmp_name'];
                $mime = mime_content_type($tmpName);
                $allowedMimes = [
                    'image/jpeg' => 'jpg',
                    'image/png'  => 'png',
                    'image/webp' => 'webp'
                ];

                if (!isset($allowedMimes[$mime])) {
                    respond(false, null, 'Formato de imagen no permitido. Usa JPG, PNG o WEBP.', 422);
                }

                $ext = $allowedMimes[$mime];
                $fileName = 'capture_' . $brandId . '_' . time() . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
                $targetPath = $uploadDir . DIRECTORY_SEPARATOR . $fileName;

                if (move_uploaded_file($tmpName, $targetPath)) {
                    $receiptPath = 'uploads/' . $fileName;
                }
            }

            $stmt = $pdo->prepare("
                INSERT INTO payments (brand_id, period_type, month_year, payment_date, amount_ves, exchange_rate, amount_usd, reference_code, notes, receipt_path)
                VALUES (:brand_id, :period_type, :month_year, :payment_date, :amount_ves, :exchange_rate, :amount_usd, :reference_code, :notes, :receipt_path)
            ");
            $stmt->execute([
                ':brand_id'       => $brandId,
                ':period_type'    => $periodType,
                ':month_year'     => $monthYear,
                ':payment_date'   => $paymentDate,
                ':amount_ves'     => $amountVes,
                ':exchange_rate'  => $exchangeRate,
                ':amount_usd'     => $amountUsd,
                ':reference_code' => $reference,
                ':notes'          => $notes,
                ':receipt_path'   => $receiptPath
            ]);

            respond(true, [
                'payment_id' => (int)$pdo->lastInsertId(),
                'amount_usd' => $amountUsd,
                'receipt'    => $receiptPath
            ], 'Pago registrado correctamente.');
            break;

        // --------------------------------------------------------------------
        // 5. ELIMINAR MARCA O PAGO
        // --------------------------------------------------------------------
        case 'delete_brand':
            $payload = getRequestPayload();
            $brandId = (int)($payload['brand_id'] ?? 0);
            $stmt = $pdo->prepare("DELETE FROM brands WHERE id = :id");
            $stmt->execute([':id' => $brandId]);
            respond(true, null, 'Marca eliminada correctamente.');
            break;

        case 'delete_payment':
            $payload   = getRequestPayload();
            $paymentId = (int)($payload['payment_id'] ?? 0);
            $stmt = $pdo->prepare("DELETE FROM payments WHERE id = :id");
            $stmt->execute([':id' => $paymentId]);
            respond(true, null, 'Registro de pago eliminado.');
            break;

        // --------------------------------------------------------------------
        // 6. GUARDAR TASA DE CAMBIO PREFERIDA
        // --------------------------------------------------------------------
        case 'save_rate':
            $payload = getRequestPayload();
            $rate = (float)($payload['exchange_rate_ves'] ?? 0);
            $mode = ($payload['exchange_rate_mode'] ?? 'auto') === 'manual' ? 'manual' : 'auto';
            if ($rate > 0) {
                $stmt = $pdo->prepare("
                    INSERT INTO settings (setting_key, setting_value) VALUES
                    ('exchange_rate_ves', :rate),
                    ('exchange_rate_mode', :mode)
                    ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
                ");
                $stmt->execute([':rate' => (string)$rate, ':mode' => $mode]);
            }
            respond(true, ['rate' => $rate, 'mode' => $mode], 'Tasa actualizada.');
            break;

        default:
            respond(false, null, 'Acción no reconocida en el servidor.', 400);
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    respond(false, ['exception' => $e->getMessage()], 'Error interno del servidor al procesar la solicitud.', 500);
}