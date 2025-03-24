<?php
// Conexión a la base de datos
$host = "localhost";
$user = "u179371012_root";
$password = "Llimon.2025";
$database = "u179371012_sipcons";

$conn = new mysqli($host, $user, $password, $database);

if ($conn->connect_error) {
    die(json_encode(["error" => "Error de conexión: " . $conn->connect_error]));
}

$id = isset($_GET["id"]) ? intval($_GET["id"]) : 0;

if ($id === 0) {
    echo json_encode(["error" => "ID de incidencia inválido"]);
    exit();
}

// Obtener los detalles de la incidencia
$sql = "SELECT * FROM incidencias WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();

$incidencia = $result->fetch_assoc();

// Obtener los archivos asociados a la incidencia
$sqlArchivos = "SELECT ruta_archivo FROM archivos_incidencias WHERE incidencia_id = ?";
$stmtArchivos = $conn->prepare($sqlArchivos);
$stmtArchivos->bind_param("i", $id);
$stmtArchivos->execute();
$resultArchivos = $stmtArchivos->get_result();

$archivos = [];
while ($row = $resultArchivos->fetch_assoc()) {
    $archivos[] = $row['ruta_archivo'];
}

// Agregar los archivos al array de la incidencia
$incidencia["archivos"] = $archivos;

$stmt->close();
$stmtArchivos->close();
$conn->close();

echo json_encode($incidencia);
?>
