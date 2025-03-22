<?php
// Configurar conexión con la base de datos
$host = "localhost";
$user = "u179371012_root";
$password = "Llimon.2025";
$database = "u179371012_sipcons";

$conn = new mysqli($host, $user, $password, $database);

// Verificar la conexión
if ($conn->connect_error) {
    die(json_encode(["error" => "Error de conexión: " . $conn->connect_error]));
}

$id = $_GET['id'];

// Obtener los detalles de la incidencia
$sql = "SELECT * FROM incidencias WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
$incidencia = $result->fetch_assoc();

if (!$incidencia) {
    echo json_encode(["error" => "Incidencia no encontrada"]);
    exit();
}

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

// Combinar los detalles de la incidencia con los archivos
$response = [
    "id" => $incidencia['id'],
    "numero_incidente" => $incidencia['numero_incidente'],
    "numero" => $incidencia['numero'],
    "cliente" => $incidencia['cliente'],
    "contacto" => $incidencia['contacto'],
    "sucursal" => $incidencia['sucursal'],
    "fecha" => $incidencia['fecha'],
    "tecnico" => $incidencia['tecnico'],
    "estatus" => $incidencia['estatus'],
    "falla" => $incidencia['falla'],
    "accion" => $incidencia['accion'],
    "archivos" => $archivos // Agregar los archivos a la respuesta
];

echo json_encode($response);

$stmt->close();
$stmtArchivos->close();
$conn->close();
?>