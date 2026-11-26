<?php
// Devuelve al almacén una refacción que se había registrado como usada en
// una incidencia (puerto acotado de inventario.php:devolver_incidencia).
// Solo se puede devolver hasta lo que de verdad sigue consumido (neto) en
// esa incidencia, para no inflar el stock por error.
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

    if ($incidenciaId <= 0 || $productoId <= 0 || $cantidad <= 0) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Faltan datos para la devolución.']);
        exit;
    }

    $pdo->beginTransaction();

    $st = $pdo->prepare('SELECT numero_incidente FROM incidencias WHERE id = ? FOR UPDATE');
    $st->execute([$incidenciaId]);
    $folio = $st->fetchColumn();
    if (!$folio) {
        throw new RuntimeException('La incidencia no existe.');
    }

    $consumidoNeto = consumoNetoProductoIncidencia($pdo, $incidenciaId, $productoId);
    if ($cantidad > $consumidoNeto) {
        throw new RuntimeException('Solo se puede devolver lo que sigue registrado como usado en esta incidencia (' . $consumidoNeto . ').');
    }

    $resultado = ajustarStockInventario(
        $pdo, $productoId, 'Entrada', $cantidad,
        "Devuelto de incidencia $folio",
        'incidencia', $incidenciaId
    );

    $pdo->commit();

    registrarAuditoria('inventario_movimientos', $productoId, 'Entrada',
        ['stock_actual' => $resultado['stock_anterior']],
        ['stock_actual' => $resultado['stock_actual'], 'incidencia' => $folio, 'cantidad' => $cantidad],
        $folio);

    echo json_encode(['success' => true] + $resultado);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $esValidacion = $e instanceof RuntimeException;
    http_response_code($esValidacion ? 422 : 500);
    echo json_encode(['success' => false, 'message' => $esValidacion ? $e->getMessage() : 'Error al devolver la refacción.']);
}
