<?php
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../auth/permisos.php';
require_once __DIR__ . '/../auth/audit.php';
require_once __DIR__ . '/lib/cxc_helpers.php';
requirePermiso('cotizaciones');
header('Content-Type: application/json');

// Convierte una cotización en venta. OJO: esto crea solo el encabezado
// financiero en `ventas` (folio, cliente, sucursal, fecha) — NO crea filas en
// `venta_detalles`/`padron_equipos`, porque esas tablas son específicas de
// equipos con número de serie/garantía/calibración y una cotización de
// SIPCONS puede ser de refacciones o servicio, no necesariamente un equipo
// nuevo. Si la cotización sí incluye un equipo para registrar en el padrón,
// eso se sigue haciendo aparte, desde Registro de Ventas (public/ventas.html),
// de forma manual.
try {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $cotizacionId = (int)($data['cotizacion_id'] ?? 0);
    $aCredito = !empty($data['a_credito']);
    $diasVencimiento = max(1, (int)($data['dias_vencimiento'] ?? 30));

    if ($cotizacionId <= 0) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Falta la cotización.']);
        exit;
    }

    $pdo->beginTransaction();

    $stmt = $pdo->prepare('SELECT * FROM cotizaciones WHERE id = ? FOR UPDATE');
    $stmt->execute([$cotizacionId]);
    $cot = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$cot) {
        throw new RuntimeException('La cotización no existe.');
    }
    if ($cot['estatus'] === 'Convertida') {
        throw new RuntimeException('Esta cotización ya se convirtió en venta.');
    }

    $fecha = date('Y-m-d');
    $ultimo = $pdo->query("SELECT folio FROM ventas ORDER BY id DESC LIMIT 1")->fetchColumn();
    $folioVenta = "VT-" . str_pad($ultimo ? (int)substr($ultimo, 3) + 1 : 1, 5, "0", STR_PAD_LEFT);

    $pdo->prepare('INSERT INTO ventas (folio, cliente, cliente_id, sucursal, fecha_registro) VALUES (?, ?, ?, ?, ?)')
        ->execute([$folioVenta, $cot['cliente'], $cot['cliente_id'], $cot['sucursal'], $fecha]);
    $ventaId = (int)$pdo->lastInsertId();

    $pdo->prepare("UPDATE cotizaciones SET estatus = 'Convertida', venta_id = ? WHERE id = ?")
        ->execute([$ventaId, $cotizacionId]);

    $cxcId = null;
    if ($aCredito) {
        $cxcId = crearCuentaPorCobrar(
            $pdo, (int)$cot['cliente_id'], $cot['cliente'], $cot['sucursal'],
            "Cotización {$cot['folio']} / Venta {$folioVenta}", (float)$cot['total'], $fecha, $diasVencimiento,
            $ventaId, $cotizacionId
        );
    }

    $pdo->commit();

    registrarAuditoria('cotizaciones', $cotizacionId, 'UPDATE', ['estatus' => $cot['estatus']], [
        'estatus' => 'Convertida', 'venta_id' => $ventaId, 'folio_venta' => $folioVenta, 'cxc_id' => $cxcId,
    ], $cot['folio']);

    echo json_encode(['success' => true, 'venta_id' => $ventaId, 'folio_venta' => $folioVenta, 'cxc_id' => $cxcId]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $esValidacion = $e instanceof RuntimeException;
    http_response_code($esValidacion ? 422 : 500);
    echo json_encode(['success' => false, 'message' => $esValidacion ? $e->getMessage() : 'Error al convertir la cotización.']);
}
