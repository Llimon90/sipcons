<?php
// Configurar conexión con la base de datos
$host = "localhost";
$user = "u179371012_root";
$password = "Llimon.2025";
$database = "u179371012_sipcons";

// Crear conexión
$conn = new mysqli($host, $user, $password, $database);

// Verificar conexión
if ($conn->connect_error) {
    die(json_encode(["error" => "Error de conexión: " . $conn->connect_error]));
}

// Recibir los parámetros de búsqueda
$cliente = isset($_GET['cliente']) ? $_GET['cliente'] : '';
$fecha_inicio = isset($_GET['fecha_inicio']) ? $_GET['fecha_inicio'] : '';
$fecha_fin = isset($_GET['fecha_fin']) ? $_GET['fecha_fin'] : '';
$estatus = isset($_GET['estatus']) ? $_GET['estatus'] : '';
$sucursal = isset($_GET['sucursal']) ? $_GET['sucursal'] : '';

// Construir la consulta SQL
$sql = "SELECT * FROM incidencias WHERE 1";

// Aplicar los filtros
if ($cliente && $cliente !== 'todos') {
    $sql .= " AND cliente = '$cliente'";
}

if ($fecha_inicio) {
    $sql .= " AND fecha >= '$fecha_inicio'";
}

if ($fecha_fin) {
    $sql .= " AND fecha <= '$fecha_fin'";
}

if ($estatus) {
    $sql .= " AND estatus = '$estatus'";
}

if ($sucursal) {
    $sql .= " AND sucursal = '$sucursal'";
}

$result = $conn->query($sql);

if ($result->num_rows > 0) {
    $incidencias = [];
    while($row = $result->fetch_assoc()) {
        $incidencias[] = $row;
    }
    echo json_encode($incidencias);
} else {
    echo json_encode(["message" => "No se encontraron datos"]);
}

// Cerrar la conexión
$conn->close();
?>
