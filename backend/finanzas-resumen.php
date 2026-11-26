<?php
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../auth/permisos.php';
requirePermiso('finanzas');
header('Content-Type: application/json');

// Panel ejecutivo: agrega ventas, cuentas por cobrar, cotizaciones e
// inventario. Es de solo lectura (no modifica nada) y, a propósito, se
// restringe a Administrador/Programador (ver migración 034) porque mezcla
// montos de todos los clientes.

$clienteId = (int)($_GET['cliente_id'] ?? 0);
$fechaInicio = !empty($_GET['fecha_inicio']) ? (string)$_GET['fecha_inicio'] : date('Y-m-01');
$fechaFin = !empty($_GET['fecha_fin']) ? (string)$_GET['fecha_fin'] : date('Y-m-d');

$filtroCliente = $clienteId > 0 ? ' AND cliente_id = ?' : '';
$paramsCliente = $clienteId > 0 ? [$clienteId] : [];

// Ventas del periodo (conteo; no hay importe en ventas de equipos, ver notas en migración 030)
$sqlVentas = "SELECT COUNT(*) AS total_ventas FROM ventas WHERE fecha_registro BETWEEN ? AND ?" . $filtroCliente;
$stmt = $pdo->prepare($sqlVentas);
$stmt->execute(array_merge([$fechaInicio, $fechaFin], $paramsCliente));
$ventasTotales = (int)$stmt->fetchColumn();

// Cuentas por cobrar vigentes (no filtradas por fecha: son saldo actual, no del periodo)
$sqlCxc = "SELECT
        COALESCE(SUM(saldo_pendiente),0) AS por_cobrar,
        COALESCE(SUM(CASE WHEN fecha_vencimiento < CURDATE() AND saldo_pendiente > 0 THEN saldo_pendiente ELSE 0 END),0) AS vencido,
        COALESCE(SUM(monto_total - saldo_pendiente),0) AS cobrado_acumulado
    FROM cuentas_por_cobrar WHERE 1=1" . $filtroCliente;
$stmt = $pdo->prepare($sqlCxc);
$stmt->execute($paramsCliente);
$cxc = $stmt->fetch(PDO::FETCH_ASSOC);

// Cobrado en el periodo (pagos registrados entre las fechas)
$sqlCobradoPeriodo = "SELECT COALESCE(SUM(p.monto),0) AS cobrado_periodo
    FROM pagos_cxc p JOIN cuentas_por_cobrar c ON c.id = p.cxc_id
    WHERE p.fecha BETWEEN ? AND ?" . ($clienteId > 0 ? ' AND c.cliente_id = ?' : '');
$stmt = $pdo->prepare($sqlCobradoPeriodo);
$stmt->execute(array_merge([$fechaInicio, $fechaFin], $paramsCliente));
$cobradoPeriodo = (float)$stmt->fetchColumn();

// Cotizaciones del periodo
$sqlCot = "SELECT estatus, COUNT(*) AS n, COALESCE(SUM(total),0) AS monto
    FROM cotizaciones WHERE fecha BETWEEN ? AND ?" . $filtroCliente . " GROUP BY estatus";
$stmt = $pdo->prepare($sqlCot);
$stmt->execute(array_merge([$fechaInicio, $fechaFin], $paramsCliente));
$cotizacionesPorEstatus = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalCotizadas = 0;
$totalConvertidas = 0;
foreach ($cotizacionesPorEstatus as $fila) {
    $totalCotizadas += (int)$fila['n'];
    if ($fila['estatus'] === 'Convertida') $totalConvertidas = (int)$fila['n'];
}
$tasaConversion = $totalCotizadas > 0 ? round($totalConvertidas / $totalCotizadas * 100, 1) : 0.0;

// Inventario (no depende de cliente/fecha: es una foto del valor actual)
$inventario = $pdo->query(
    "SELECT COALESCE(SUM(stock_actual * costo),0) AS valor_inventario,
            SUM(CASE WHEN stock_actual <= stock_minimo THEN 1 ELSE 0 END) AS productos_bajo_minimo
     FROM inventario_productos WHERE activo = 1"
)->fetch(PDO::FETCH_ASSOC);

// Desglose por cliente -> sucursal (ventas + saldo por cobrar), para el
// tablero "finanzas por cliente/sucursal" que pidió el usuario.
$sqlDesglose = "SELECT c.nombre AS cliente, v.sucursal, COUNT(*) AS ventas
    FROM ventas v JOIN clientes c ON c.id = v.cliente_id
    WHERE v.fecha_registro BETWEEN ? AND ?" . ($clienteId > 0 ? ' AND v.cliente_id = ?' : '') . "
    GROUP BY c.nombre, v.sucursal ORDER BY c.nombre, v.sucursal";
$stmt = $pdo->prepare($sqlDesglose);
$stmt->execute(array_merge([$fechaInicio, $fechaFin], $paramsCliente));
$desgloseVentas = $stmt->fetchAll(PDO::FETCH_ASSOC);

$sqlDesgloseCxc = "SELECT cliente, sucursal, COALESCE(SUM(saldo_pendiente),0) AS saldo_pendiente
    FROM cuentas_por_cobrar WHERE 1=1" . $filtroCliente . " GROUP BY cliente, sucursal";
$stmt = $pdo->prepare($sqlDesgloseCxc);
$stmt->execute($paramsCliente);
$desgloseCxc = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'periodo' => ['inicio' => $fechaInicio, 'fin' => $fechaFin],
    'ventas_totales' => $ventasTotales,
    'cuentas_por_cobrar' => [
        'por_cobrar' => round((float)$cxc['por_cobrar'], 2),
        'vencido' => round((float)$cxc['vencido'], 2),
        'cobrado_acumulado' => round((float)$cxc['cobrado_acumulado'], 2),
        'cobrado_periodo' => round($cobradoPeriodo, 2),
    ],
    'cotizaciones' => [
        'por_estatus' => $cotizacionesPorEstatus,
        'total' => $totalCotizadas,
        'convertidas' => $totalConvertidas,
        'tasa_conversion_pct' => $tasaConversion,
    ],
    'inventario' => [
        'valor_total' => round((float)$inventario['valor_inventario'], 2),
        'productos_bajo_minimo' => (int)$inventario['productos_bajo_minimo'],
    ],
    'desglose_cliente_sucursal' => [
        'ventas' => $desgloseVentas,
        'cuentas_por_cobrar' => $desgloseCxc,
    ],
]);
