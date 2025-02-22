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

if ($method === "GET") {
    // Consulta para obtener todas las clientes
    $sql = "SELECT * FROM clientes";
    $result = $conn->query($sql);

    if ($result->num_rows > 0) {
        $clientes = [];
        while($row = $result->fetch_assoc()) {
            $clientes[] = $row;
        }
        echo json_encode($clientes);
    } else {
        echo json_encode(["message" => "No hay clientes registrados"]);
    }
} elseif ($method === "POST") {
    // Leer los datos enviados desde fetch()
    $data = json_decode(file_get_contents("php://input"), true);

    // Validar los datos
    if (!isset($data["nombre"], $data["rfc"], $data["direccion"], $data["telefono"], $data["contactos"], $data["email"])) {
        echo json_encode(["error" => "Todos los campos son obligatorios"]);
        exit();
    }

    
    // Insertar nueuvo cliente
    $sql = "INSERT INTO clientes (nombre, rfc, direccion, telefono, contacto, email) VALUES (?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sssssssss", $data["nombre"], $data["rfc"], $data["direccion"], $data["telefono"], $data["contactos"], $data["email"]);

    if ($stmt->execute()) {
        echo json_encode(["message" => "Cliente registrado correctamente" ]);
    } else {
        echo json_encode(["error" => "Error al registrar nuevo cliente"]);
    }

    $stmt->close();
}

$conn->close();
?>