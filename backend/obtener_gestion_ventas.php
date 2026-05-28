<?php
header('Content-Type: application/json');
require_once 'conexion.php';

try {
    // Esta consulta mágica agrupa todas las marcas, modelos y suma los servicios
    $sql = "SELECT 
                v.id, 
                v.folio, 
                v.fecha_registro, 
                v.cliente, 
                v.sucursal,
                COUNT(d.id) as cantidad_equipos,
                GROUP_CONCAT(DISTINCT d.equipo SEPARATOR ', ') as equipos,
                GROUP_CONCAT(DISTINCT d.marca SEPARATOR ', ') as marcas,
                GROUP_CONCAT(DISTINCT d.modelo SEPARATOR ', ') as modelos,
                SUM(d.servicio) as equipos_con_servicio,
                (SELECT COUNT(*) FROM venta_archivos a WHERE a.venta_id = v.id) as total_archivos
            FROM ventas v
            LEFT JOIN venta_detalles d ON v.id = d.venta_id
            GROUP BY v.id
            ORDER BY v.id DESC";

    $stmt = $pdo->query($sql);
    $ventas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Los KPIs globales iniciales
    $total_ventas = count($ventas);
    $total_equipos = array_sum(array_column($ventas, 'cantidad_equipos'));
    $con_servicio = array_sum(array_column($ventas, 'equipos_con_servicio'));

    echo json_encode([
        'exito' => true,
        'stats' => [
            'total_ventas' => $total_ventas,
            'total_equipos' => $total_equipos,
            'con_servicio' => $con_servicio
        ],
        'ventas' => $ventas
    ]);

} catch (Exception $e) {
    echo json_encode(['exito' => false, 'mensaje' => $e->getMessage()]);
}
?>