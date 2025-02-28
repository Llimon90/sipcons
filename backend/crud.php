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
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

// Leer el método HTTP
$method = $_SERVER["REQUEST_METHOD"];

if ($method === "GET" && isset($_GET["id"])) {
    // Obtener una incidencia por ID
    $id = intval($_GET["id"]);
    $sql = "SELECT * FROM incidencias WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        echo json_encode($row);
    } else {
        echo json_encode(["error" => "Incidencia no encontrada"]);
    }
    $stmt->close();

} elseif ($method === "GET") {
    // Listar todas las incidencias
    $sql = "SELECT * FROM incidencias WHERE estatus = 'Abierta'";
    $result = $conn->query($sql);

    if ($result->num_rows > 0) {
        $incidencias = [];
        while ($row = $result->fetch_assoc()) {
            $incidencias[] = $row;
        }
        echo json_encode($incidencias);
    } else {
        echo json_encode(["message" => "No hay incidencias abiertas"]);
    }

} elseif ($method === "POST") {
    // Actualizar estatus de una incidencia
    $data = json_decode(file_get_contents("php://input"), true);

    if (!isset($data["id"], $data["estatus"])) {
        echo json_encode(["error" => "ID y estatus son requeridos"]);
        exit();
    }

    $id = intval($data["id"]);
    $estatus = $data["estatus"];

    $sql = "UPDATE incidencias SET estatus = ? WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("si", $estatus, $id);

    if ($stmt->execute()) {
        echo json_encode(["message" => "Estatus actualizado correctamente"]);
    } else {
        echo json_encode(["error" => "Error al actualizar estatus"]);
    }

    $stmt->close();
}

$conn->close();
?>
