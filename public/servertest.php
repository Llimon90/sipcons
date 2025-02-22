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
// header("Access-Control-Allow-Origin: *");
// header("Content-Type: application/json");
// header("Access-Control-Allow-Methods: GET");
// header("Access-Control-Allow-Headers: Content-Type");

// Leer el método HTTP
$method = $_SERVER["REQUEST_METHOD"];

if ($method === "GET") {
    // Consulta para obtener todas las incidencias sin filtrar
    $sql = "SELECT * FROM incidencias";
    $result = $conn->query($sql);

    if ($result->num_rows > 0) {
        $incidencias = [];
        while($row = $result->fetch_assoc()) {
            $incidencias[] = $row;
        }
        echo json_encode($incidencias);
    } else {
        echo json_encode(["message" => "No hay incidencias abiertas"]);
    }
}

$conn->close();
?>
