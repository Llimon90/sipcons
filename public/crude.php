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

    // Validar los datos
    if (!isset($data["id"], $data["numero"], $data["cliente"], $data["contacto"], $data["sucursal"], $data["fecha"], $data["tecnico"], $data["estatus"])) {
        echo json_encode(["error" => "Todos los campos son obligatorios"]);
        exit();
    }

    // Actualizar la incidencia en la base de datos
    $sql = "UPDATE incidencias SET numero = ?, cliente = ?, contacto = ?, sucursal = ?, fecha = ?, tecnico = ?, estatus = ? WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssssssssi", $data["numero"], $data["cliente"], $data["contacto"], $data["sucursal"], $data["fecha"], $data["tecnico"], $data["estatus"], $data["id"]);

    if ($stmt->execute()) {
        echo json_encode(["success" => true]);
    } else {
        echo json_encode(["error" => "Error al actualizar incidencia"]);
    }

    $stmt->close();
}

$conn->close();
?>
