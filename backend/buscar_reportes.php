<?php
// buscar_reportes.php

require_once 'conexion.php';

if ($conn->connect_error) {
    die(json_encode(["error" => "Error de conexión: " . $conn->connect_error]));
}

// Obtener parámetros
$cliente      = isset($_GET['cliente']) ? trim($_GET['cliente']) : '';
$fecha_inicio = isset($_GET['fecha_inicio']) ? trim($_GET['fecha_inicio']) : '';
$fecha_fin    = isset($_GET['fecha_fin']) ? trim($_GET['fecha_fin']) : '';
$estatus      = isset($_GET['estatus']) ? trim($_GET['estatus']) : '';
$sucursal     = isset($_GET['sucursal']) ? trim($_GET['sucursal']) : '';
$tecnico      = isset($_GET['tecnico']) ? trim($_GET['tecnico']) : '';
$tipo_equipo  = isset($_GET['tipo_equipo']) ? trim($_GET['tipo_equipo']) : '';
$solo_activas = isset($_GET['solo_activas']) ? trim($_GET['solo_activas']) : '';

// Comenzar consulta
$sql = "SELECT * FROM incidencias WHERE 1=1";
$params = [];
$types = "";

// Filtros comunes
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

if (!empty($estatus)) {
    $sql .= " AND estatus = ?";
    $params[] = $estatus;
    $types .= "s";
}

if (!empty($sucursal)) {
    $sql .= " AND sucursal LIKE ?";
    $params[] = "%$sucursal%";
    $types .= "s";
}

if (!empty($tecnico)) {
    $sql .= " AND tecnico LIKE ?";
    $params[] = "%$tecnico%";
    $types .= "s";
}

if (!empty($solo_activas) && $solo_activas === '1') {
    $sql .= " AND estatus IN ('Abierto', 'Asignado', 'Pendiente', 'Completado')";
}

// Filtro rápido basado en “equipo” - CORREGIDO
if (!empty($tipo_equipo)) {
    if ($tipo_equipo === 'Mr. Tienda/Mr. Chef') {
        $sql .= " AND equipo = 'Mr. Tienda/Mr. Chef'";
    } elseif ($tipo_equipo === 'Otros') {
        $sql .= " AND equipo = 'Otros'";
    }
}

// Orden
$sql .= " ORDER BY id DESC";

// Preparar
$stmt = $conn->prepare($sql);
if (!$stmt) {
    // En caso de error en la preparación, devolver mensaje
    die(json_encode(["error" => "Error en prepare(): " . $conn->error, "sql" => $sql]));
}

// Vincular parámetros si los hay
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

// Ejecutar
$stmt->execute();

// Obtener resultados
$result = $stmt->get_result();
$incidencias = [];
while ($fila = $result->fetch_assoc()) {
    $incidencias[] = $fila;
}

// Si no hay resultados
if (empty($incidencias)) {
    echo json_encode(["message" => "No se encontraron datos", "debug_sql" => $sql]);
} else {
    echo json_encode($incidencias);
}

// Cerrar
$stmt->close();
$conn->close();
?>