<?php
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../auth/permisos.php';
require_once __DIR__ . '/../auth/audit.php';
requirePermiso('cuentas_por_cobrar');
header('Content-Type: application/json');

try {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];

    $cxcId = (int)($data['cxc_id'] ?? 0);
    $monto = round((float)($data['monto'] ?? 0), 2);
    $fecha = !empty($data['fecha']) ? (string)$data['fecha'] : date('Y-m-d');
    $metodoPago = trim((string)($data['metodo_pago'] ?? ''));
    $referencia = trim((string)($data['referencia'] ?? ''));
    $notas = trim((string)($data['notas'] ?? ''));

    if ($cxcId <= 0 || $monto <= 0) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Falta la cuenta por cobrar o el monto del pago.']);
        exit;
    }

    $pdo->beginTransaction();

    $stmt = $pdo->prepare('SELECT saldo_pendiente, monto_total FROM cuentas_por_cobrar WHERE id = ? FOR UPDATE');
    $stmt->execute([$cxcId]);
    $cxc = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$cxc) {
        throw new RuntimeException('La cuenta por cobrar no existe.');
    }
    if ($monto > (float)$cxc['saldo_pendiente']) {
        throw new RuntimeException('El pago no puede ser mayor al saldo pendiente (' . number_format((float)$cxc['saldo_pendiente'], 2) . ').');
    }

    $pdo->prepare(
        'INSERT INTO pagos_cxc (cxc_id, monto, fecha, metodo_pago, referencia, notas, usuario_id, usuario_nombre)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $cxcId, $monto, $fecha, $metodoPago !== '' ? $metodoPago : null, $referencia !== '' ? $referencia : null,
        $notas !== '' ? $notas : null, $_SESSION['user_id'] ?? null, $_SESSION['nombre'] ?? ($_SESSION['usuario'] ?? null),
    ]);

    $nuevoSaldo = round((float)$cxc['saldo_pendiente'] - $monto, 2);
    $nuevoEstatus = $nuevoSaldo <= 0 ? 'Pagada' : 'Parcial';
    $pdo->prepare('UPDATE cuentas_por_cobrar SET saldo_pendiente = ?, estatus = ? WHERE id = ?')
        ->execute([$nuevoSaldo, $nuevoEstatus, $cxcId]);

    $pdo->commit();

    registrarAuditoria('pagos_cxc', $cxcId, 'CREATE', ['saldo_pendiente' => $cxc['saldo_pendiente']], [
        'monto_pagado' => $monto, 'saldo_pendiente' => $nuevoSaldo, 'estatus' => $nuevoEstatus,
    ]);

    echo json_encode(['success' => true, 'saldo_pendiente' => $nuevoSaldo, 'estatus' => $nuevoEstatus]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $esValidacion = $e instanceof RuntimeException;
    http_response_code($esValidacion ? 422 : 500);
    echo json_encode(['success' => false, 'message' => $esValidacion ? $e->getMessage() : 'Error al registrar el pago.']);
}
