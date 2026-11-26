<?php
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../auth/permisos.php';
require_once __DIR__ . '/../auth/audit.php';
require_once __DIR__ . '/lib/inventario_helpers.php';
requirePermiso('inventario');
header('Content-Type: application/json');

try {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $productoId = (int)($data['producto_id'] ?? 0);
    $tipo = (string)($data['tipo'] ?? '');
    $cantidad = (int)($data['cantidad'] ?? 0);
    $motivo = trim((string)($data['motivo'] ?? ''));

    if ($productoId <= 0 || $cantidad <= 0 || !in_array($tipo, ['Entrada', 'Salida', 'Ajuste'], true)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Datos de movimiento inválidos.']);
        exit;
    }

    $pdo->beginTransaction();
    $resultado = ajustarStockInventario($pdo, $productoId, $tipo, $cantidad, $motivo !== '' ? $motivo : null);
    $pdo->commit();

    registrarAuditoria('inventario_movimientos', $productoId, $tipo, ['stock_actual' => $resultado['stock_anterior']], ['stock_actual' => $resultado['stock_actual'], 'motivo' => $motivo]);

    echo json_encode(['success' => true] + $resultado);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $esValidacion = $e instanceof RuntimeException;
    http_response_code($esValidacion ? 422 : 500);
    echo json_encode(['success' => false, 'message' => $esValidacion ? $e->getMessage() : 'Error al registrar el movimiento.']);
}
