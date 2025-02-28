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

// Permitir solicitudes desde el frontend
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

// Leer el método HTTP
$method = $_SERVER["REQUEST_METHOD"];

if ($method === "POST") {
    // Leer los datos enviados desde fetch()
    $data = json_decode(file_get_contents("php://input"), true);

    if (!isset($data["id"]) || !isset($data["estatus"])) {
        echo json_encode(["error" => "Faltan datos"]);
        exit;
    }

    $id = intval($data["id"]);
    $estatus = $data["estatus"];

    // Validar estatus permitido (opcional, pero recomendable)
    $estatusPermitidos = ["pendiente", "en proceso", "facturada", "cerrada"];
    if (!in_array($estatus, $estatusPermitidos)) {
        echo json_encode(["error" => "Estatus inválido"]);
        exit;
    }

    // Actualizar la incidencia
    $sql = "UPDATE incidencias SET estatus = ? WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("si", $estatus, $id);

    if ($stmt->execute()) {
        echo json_encode(["message" => "Incidencia actualizada correctamente", "id" => $id, "estatus" => $estatus]);
    } else {
        echo json_encode(["error" => "Error al actualizar incidencia"]);
    }

    $stmt->close();
}

$conn->close();
?>
