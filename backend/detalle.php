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

$sql = "SELECT * FROM incidencias WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();

if ($row = $result->fetch_assoc()) {
    echo json_encode($row);
} else {
    echo json_encode(["error" => "No se encontró la incidencia."]);
}

$stmt->close();
$conn->close();
?>
