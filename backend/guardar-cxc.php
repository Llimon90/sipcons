<?php
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../auth/permisos.php';
require_once __DIR__ . '/../auth/audit.php';
require_once __DIR__ . '/lib/cxc_helpers.php';
requirePermiso('cuentas_por_cobrar');
header('Content-Type: application/json');

try {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];

    $clienteId = (int)($data['cliente_id'] ?? 0);
    $concepto = trim((string)($data['concepto'] ?? ''));
    $sucursal = trim((string)($data['sucursal'] ?? ''));
    $montoTotal = round((float)($data['monto_total'] ?? 0), 2);
    $fechaEmision = !empty($data['fecha_emision']) ? (string)$data['fecha_emision'] : date('Y-m-d');
    $diasVencimiento = max(1, (int)($data['dias_vencimiento'] ?? 30));

    if ($clienteId <= 0 || $concepto === '' || $montoTotal <= 0) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Cliente, concepto y monto (mayor a cero) son obligatorios.']);
        exit;
    }

    $stmtCliente = $pdo->prepare('SELECT nombre FROM clientes WHERE id = ?');
    $stmtCliente->execute([$clienteId]);
    $nombreCliente = $stmtCliente->fetchColumn();
    if (!$nombreCliente) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'El cliente no existe.']);
        exit;
    }

    $pdo->beginTransaction();
    $id = crearCuentaPorCobrar($pdo, $clienteId, $nombreCliente, $sucursal !== '' ? $sucursal : null, $concepto, $montoTotal, $fechaEmision, $diasVencimiento);
    $pdo->commit();

    registrarAuditoria('cuentas_por_cobrar', $id, 'CREATE', null, [
        'cliente' => $nombreCliente, 'concepto' => $concepto, 'monto_total' => $montoTotal,
    ]);

    echo json_encode(['success' => true, 'id' => $id]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error al registrar la cuenta por cobrar.']);
}
