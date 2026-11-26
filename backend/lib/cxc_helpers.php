<?php
// Debe llamarse dentro de una transacción ya abierta por el endpoint que la
// origina (alta manual o conversión de cotización), para que la CxC y su
// origen siempre queden juntos o ninguno de los dos.
function crearCuentaPorCobrar(
    PDO $pdo,
    int $clienteId,
    string $cliente,
    ?string $sucursal,
    string $concepto,
    float $montoTotal,
    string $fechaEmision,
    int $diasVencimiento = 30,
    ?int $ventaId = null,
    ?int $cotizacionId = null
): int {
    $fechaVencimiento = date('Y-m-d', strtotime("$fechaEmision +$diasVencimiento days"));

    $pdo->prepare(
        'INSERT INTO cuentas_por_cobrar
            (cliente_id, cliente, sucursal, concepto, venta_id, cotizacion_id, monto_total, saldo_pendiente, fecha_emision, fecha_vencimiento, creado_por)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $clienteId, $cliente, $sucursal, $concepto, $ventaId, $cotizacionId, $montoTotal, $montoTotal,
        $fechaEmision, $fechaVencimiento, $_SESSION['nombre'] ?? ($_SESSION['usuario'] ?? null),
    ]);

    return (int)$pdo->lastInsertId();
}

// Estatus derivado para listados: no se cambia en BD aquí, solo para pintar.
// El estatus real en BD se actualiza al registrar pagos (backend/registrar-pago-cxc.php).
function estatusVisibleCxc(array $cxc): string {
    if ($cxc['estatus'] === 'Pagada') return 'Pagada';
    $vencida = $cxc['fecha_vencimiento'] < date('Y-m-d') && (float)$cxc['saldo_pendiente'] > 0;
    if ($vencida) return 'Vencida';
    return (float)$cxc['saldo_pendiente'] < (float)$cxc['monto_total'] ? 'Parcial' : 'Pendiente';
}
