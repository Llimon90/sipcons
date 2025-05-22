<?php
// Configurar conexión con la base de datos
require_once 'conexion.php';

// Verificar conexión
if ($conn->connect_error) {
    die(json_encode(["error" => "Error de conexión: " . $conn->connect_error]));
}

// Recibir los parámetros de búsqueda y sanitizarlos
$cliente = isset($_GET['cliente']) ? trim($_GET['cliente']) : '';
$fecha_inicio = isset($_GET['fecha_inicio']) ? trim($_GET['fecha_inicio']) : '';
$fecha_fin = isset($_GET['fecha_fin']) ? trim($_GET['fecha_fin']) : '';
$estatus = isset($_GET['estatus']) ? trim($_GET['estatus']) : '';
$sucursal = isset($_GET['sucursal']) ? trim($_GET['sucursal']) : '';
$tecnico = isset($_GET['tecnico']) ? trim($_GET['tecnico']) : '';

// Construir la consulta SQL con `prepared statements`
$sql = "SELECT * FROM incidencias WHERE 1";

$params = [];
$types = "";

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

// Agregar orden de más reciente a más antiguo
$sql .= " ORDER BY id DESC";

// Preparar y ejecutar la consulta
$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

$incidencias = [];
while ($row = $result->fetch_assoc()) {
    $incidencias[] = $row;
}

echo json_encode($incidencias ?: ["message" => "No se encontraron datos"]);

// Cerrar la conexión
$stmt->close();
$conn->close();
?>
