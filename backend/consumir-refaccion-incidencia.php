<?php
// Registra una refacción usada en una incidencia (puerto acotado de
// backend/inventario.php:consumir_incidencia de Servidesk, sin series ni
// equipo de reemplazo). Requiere permiso de incidencias, no de inventario:
// el técnico registra lo que usó sin necesitar acceso al almacén completo.
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../auth/permisos.php';
require_once __DIR__ . '/../auth/audit.php';
require_once __DIR__ . '/lib/inventario_helpers.php';
requirePermiso('incidencias');
header('Content-Type: application/json');

try {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $incidenciaId = (int)($data['incidencia_id'] ?? 0);
    $productoId = (int)($data['producto_id'] ?? 0);
    $cantidad = (int)($data['cantidad'] ?? 0);
    $motivo = trim((string)($data['motivo'] ?? ''));

    if ($incidenciaId <= 0 || $productoId <= 0 || $cantidad <= 0) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Faltan datos para registrar la refacción usada.']);
        exit;
    }

    $pdo->beginTransaction();

    $st = $pdo->prepare('SELECT numero_incidente FROM incidencias WHERE id = ? FOR UPDATE');
    $st->execute([$incidenciaId]);
    $folio = $st->fetchColumn();
    if (!$folio) {
        throw new RuntimeException('La incidencia no existe.');
    }

    $resultado = ajustarStockInventario(
        $pdo, $productoId, 'Salida', $cantidad,
        $motivo !== '' ? $motivo : "Usado en incidencia $folio",
        'incidencia', $incidenciaId
    );

    $pdo->commit();

    registrarAuditoria('inventario_movimientos', $productoId, 'Salida',
        ['stock_actual' => $resultado['stock_anterior']],
        ['stock_actual' => $resultado['stock_actual'], 'incidencia' => $folio, 'cantidad' => $cantidad],
        $folio);

    echo json_encode(['success' => true] + $resultado);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $esValidacion = $e instanceof RuntimeException;
    http_response_code($esValidacion ? 422 : 500);
    echo json_encode(['success' => false, 'message' => $esValidacion ? $e->getMessage() : 'Error al registrar la refacción usada.']);
}
