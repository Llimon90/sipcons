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

// Obtener parámetros del formulario (desde el frontend)
$cliente = isset($_GET['cliente']) ? $_GET['cliente'] : '';
$fechaInicio = isset($_GET['fecha-inicio']) ? $_GET['fecha-inicio'] : '';
$fechaFin = isset($_GET['fecha-fin']) ? $_GET['fecha-fin'] : '';

// Preparar la consulta SQL
$sql = "SELECT id, cliente, fecha, descripcion FROM reportes WHERE 1=1";

// Aplicar filtros si existen
if ($cliente !== 'todos' && $cliente !== '') {
    $sql .= " AND cliente = '$cliente'";
}

if ($fechaInicio !== '') {
    $sql .= " AND fecha >= '$fechaInicio'";
}

if ($fechaFin !== '') {
    $sql .= " AND fecha <= '$fechaFin'";
}

// Ejecutar la consulta
$result = $conn->query($sql);

// Verificar si hay resultados
if ($result->num_rows > 0) {
    $reportes = [];
    while ($row = $result->fetch_assoc()) {
        $reportes[] = $row;
    }
    echo json_encode($reportes);
} else {
    echo json_encode(["mensaje" => "No se encontraron reportes con los filtros seleccionados."]);
}

// Cerrar la conexión
$conn->close();
?>
