<?php
// buscar_reportes.php

ob_start(); 
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    require_once 'conexion.php';

    header("Access-Control-Allow-Origin: *");
    header("Content-Type: application/json");
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    header("Pragma: no-cache");

    $cliente          = isset($_GET['cliente']) ? trim($_GET['cliente']) : '';
    $fecha_inicio     = isset($_GET['fecha_inicio']) ? trim($_GET['fecha_inicio']) : '';
    $fecha_fin        = isset($_GET['fecha_fin']) ? trim($_GET['fecha_fin']) : '';
    $estatus          = isset($_GET['estatus']) ? trim($_GET['estatus']) : '';
    $sucursal         = isset($_GET['sucursal']) ? trim($_GET['sucursal']) : '';
    $tecnico          = isset($_GET['tecnico']) ? trim($_GET['tecnico']) : '';
    $tipo_equipo      = isset($_GET['tipo_equipo']) ? trim($_GET['tipo_equipo']) : '';
    $solo_activas     = isset($_GET['solo_activas']) ? trim($_GET['solo_activas']) : '';
    $solo_programadas = isset($_GET['solo_programadas']) ? trim($_GET['solo_programadas']) : '';

    $params = [];
    $types = "";

    if (!empty($solo_programadas) && $solo_programadas === '1') {
        // CORRECCIÓN: COALESCE(p.garantia, 0) añadido en el GROUP_CONCAT
        $sql = "SELECT * FROM (
            SELECT 
                MIN(p.id) as id,
                'PROG-CAL' as numero_incidente,
                IF(p.origen = 'Venta Lumina', CONCAT('Venta #', p.venta_id), 'Equipo Externo') as numero,
                p.cliente as cliente,
                p.sucursal as sucursal,
                CONCAT(COUNT(p.id), ' equipo(s) a Calibrar.') as falla,
                p.proxima_calibracion as fecha,
                'Programado' as estatus,
                MAX(p.equipo) as equipo,
                'Por asignar' as tecnico,
                GROUP_CONCAT(CONCAT_WS('~', p.marca, p.modelo, COALESCE(p.numero_serie, 'S/N'), COALESCE(p.calibracion, 0), COALESCE(p.frecuencia_servicio, 0), COALESCE(p.garantia, 0), COALESCE(DATE(p.fecha_registro), '')) SEPARATOR '||') as detalles_completos
            FROM padron_equipos p
            WHERE p.calibracion > 0 AND p.proxima_calibracion IS NOT NULL
            GROUP BY p.venta_id, p.origen, p.cliente, p.sucursal, p.proxima_calibracion
            
            UNION ALL
            
            SELECT 
                MIN(p.id) as id,
                'PROG-SERV' as numero_incidente,
                IF(p.origen = 'Venta Lumina', CONCAT('Venta #', p.venta_id), 'Equipo Externo') as numero,
                p.cliente as cliente,
                p.sucursal as sucursal,
                CONCAT(COUNT(p.id), ' equipo(s) a Servicio.') as falla,
                p.proximo_servicio as fecha,
                'Programado' as estatus,
                MAX(p.equipo) as equipo,
                'Por asignar' as tecnico,
                GROUP_CONCAT(CONCAT_WS('~', p.marca, p.modelo, COALESCE(p.numero_serie, 'S/N'), COALESCE(p.calibracion, 0), COALESCE(p.frecuencia_servicio, 0), COALESCE(p.garantia, 0), COALESCE(DATE(p.fecha_registro), '')) SEPARATOR '||') as detalles_completos
            FROM padron_equipos p
            WHERE p.servicio = 1 AND p.frecuencia_servicio > 0 AND p.proximo_servicio IS NOT NULL
            GROUP BY p.venta_id, p.origen, p.cliente, p.sucursal, p.proximo_servicio
        ) AS programadas WHERE 1=1";
    } else {
        $sql = "SELECT id, numero_incidente, numero, cliente, sucursal, falla, fecha, estatus, equipo, tecnico, '' as detalles_completos 
                FROM incidencias WHERE 1=1";
                
        if (!empty($solo_activas) && $solo_activas === '1') {
            $sql .= " AND estatus IN ('Abierto', 'Asignado', 'Pendiente', 'Completado')";
        }
        if (!empty($estatus)) {
            $sql .= " AND estatus = ?";
            $params[] = $estatus;
            $types .= "s";
        }
        if (!empty($tecnico)) {
            $sql .= " AND tecnico LIKE ?";
            $params[] = "%$tecnico%";
            $types .= "s";
        }
    }

    if (!empty($cliente) && $cliente !== 'todos') {
        $sql .= " AND cliente = ?";
        $params[] = $cliente;
        $types .= "s";
    }
    if (!empty($fecha_inicio)) {
        $sql .= " AND fecha >= ?";
        $params[] = $fecha_inicio;
        $types .= "s";
    }
    if (!empty($fecha_fin)) {
        $sql .= " AND fecha <= ?";
        $params[] = $fecha_fin;
        $types .= "s";
    }
    if (!empty($sucursal)) {
        $sql .= " AND sucursal LIKE ?";
        $params[] = "%$sucursal%";
        $types .= "s";
    }
    if (!empty($tipo_equipo)) {
        $sql .= " AND equipo = ?";
        $params[] = $tipo_equipo;
        $types .= "s";
    }

    if (!empty($solo_programadas) && $solo_programadas === '1') {
        $sql .= " ORDER BY fecha ASC";
    } else {
        $sql .= " ORDER BY id DESC";
    }

    $stmt = $conn->prepare($sql);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    $incidencias = [];

    while ($fila = $result->fetch_assoc()) {
        $incidencias[] = $fila;
    }

    if (ob_get_length()) ob_clean();
    echo json_encode(empty($incidencias) ? ["message" => "No se encontraron datos"] : $incidencias);

    $stmt->close();
    $conn->close();

} catch (Exception $e) {
    if (ob_get_length()) ob_clean();
    echo json_encode(["error" => "Error atrapado en PHP: " . $e->getMessage()]);
    exit;
}
?>