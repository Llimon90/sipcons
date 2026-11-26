<?php
// KPIs de inventario (puerto acotado de inventario.php:GET resumen de
// Servidesk): valor total, bajo mínimo, agotados, movimientos del mes y
// consumo de los últimos 6 meses por origen (incidencia/venta/manual).
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../auth/permisos.php';
requirePermiso('inventario');
header('Content-Type: application/json');

$kpi = $pdo->query(
    "SELECT COUNT(*) AS articulos,
            COALESCE(SUM(stock_actual * costo), 0) AS valor,
            SUM(stock_actual <= stock_minimo) AS bajo_minimo,
            SUM(stock_actual <= 0) AS agotados
     FROM inventario_productos WHERE activo = 1"
)->fetch(PDO::FETCH_ASSOC);

$kpi['movimientos_30d'] = (int)$pdo->query(
    "SELECT COUNT(*) FROM inventario_movimientos WHERE creado_en >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
)->fetchColumn();

$consumoMensual = $pdo->query(
    "SELECT DATE_FORMAT(creado_en, '%Y-%m') AS mes,
            COALESCE(referencia_tipo, 'manual') AS origen,
            SUM(cantidad * costo_momento) AS valor,
            SUM(cantidad) AS unidades
     FROM (
        SELECT m.creado_en, m.referencia_tipo, m.cantidad, p.costo AS costo_momento
        FROM inventario_movimientos m
        JOIN inventario_productos p ON p.id = m.producto_id
        WHERE m.tipo = 'Salida' AND m.creado_en >= DATE_SUB(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 5 MONTH)
     ) s
     GROUP BY mes, origen ORDER BY mes"
)->fetchAll(PDO::FETCH_ASSOC);

$masUsados = $pdo->query(
    "SELECT p.id, p.sku, p.nombre, p.unidad, SUM(m.cantidad) AS unidades
     FROM inventario_movimientos m JOIN inventario_productos p ON p.id = m.producto_id
     WHERE m.tipo = 'Salida' AND m.referencia_tipo IN ('incidencia', 'venta')
       AND m.creado_en >= DATE_SUB(NOW(), INTERVAL 180 DAY)
     GROUP BY p.id, p.sku, p.nombre, p.unidad
     ORDER BY unidades DESC LIMIT 5"
)->fetchAll(PDO::FETCH_ASSOC);

echo json_encode(['success' => true, 'kpi' => $kpi, 'consumo_mensual' => $consumoMensual, 'mas_usados' => $masUsados]);
