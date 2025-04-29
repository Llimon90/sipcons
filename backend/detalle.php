<?php
// Cone xión a la base de datos
$host = "localhost";
$user = "sipcons1_appweb";
$password = "sip*SYS2025";
$database = "sipcons1_appweb";

$conn = new mysqli($host, $user, $password, $database);

if ($conn->connect_error) {
    die(json_encode(["error" => "Error de conexión: " . $conn->connect_error]));
}

$id = isset($_GET["id"]) ? intval($_GET["id"]) : 0;

if ($id === 0) {
    echo json_encode(["error" => "ID de incidencia inválido"]);
    exit();
}

// Obtener datos de la incidencia
$sql = "SELECT * FROM incidencias WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
$data = $result->fetch_assoc();
$stmt->close();

// Obtener archivos de la incidencia
$sql = "SELECT ruta_archivo FROM archivos_incidencias WHERE incidencia_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();

$archivos = [];
while ($row = $result->fetch_assoc()) {
    $archivos[] = $row["ruta_archivo"];
}
$stmt->close();

$data["archivos"] = $archivos;

$conn->close();

echo json_encode($data);
?>
