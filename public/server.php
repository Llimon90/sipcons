<?php
// Configuración de la base de datos
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

// Detectar el método HTTP
$method = $_SERVER["REQUEST_METHOD"];

if ($method === "GET") {
    // Consulta para obtener todas las incidencias
    $sql = "SELECT id, numero, numero_incidente, cliente, contacto, sucursal, falla, fecha, tecnico, estatus FROM incidencias ORDER BY id DESC";
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
}

if ($method === "POST") {
    // Leer los datos recibidos
    $data = json_decode(file_get_contents("php://input"), true);

    // Validar los datos obligatorios
    $requiredFields = ["numero", "cliente", "contacto", "sucursal", "fecha", "tecnico", "estatus", "falla"];
    foreach ($requiredFields as $field) {
        if (!isset($data[$field]) || empty($data[$field])) {
            http_response_code(400);
            echo json_encode(["error" => "El campo '$field' es obligatorio"]);
            exit();
        }
    }

    // Obtener el último número de incidencia
    $sqlUltimoNumero = "SELECT numero_incidente FROM incidencias ORDER BY id DESC LIMIT 1";
    $result = $conn->query($sqlUltimoNumero);

    $nuevoNumeroIncidente = "SIP-0001"; // Valor inicial si no hay registros previos

    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        preg_match('/\d+/', $row["numero_incidente"], $matches);
        $numeroIncremental = isset($matches[0]) ? intval($matches[0]) + 1 : 1;
        $nuevoNumeroIncidente = "SIP-" . str_pad($numeroIncremental, 4, "0", STR_PAD_LEFT);
    }

    // Insertar la incidencia en la base de datos
    $sql = "INSERT INTO incidencias (numero, cliente, contacto, sucursal, fecha, tecnico, estatus, falla, numero_incidente) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sssssssss", 
        $data["numero"], 
        $data["cliente"], 
        $data["contacto"], 
        $data["sucursal"], 
        $data["fecha"], 
        $data["tecnico"], 
        $data["estatus"], 
        $data["falla"], 
        $nuevoNumeroIncidente
    );

    if ($stmt->execute()) {
        echo json_encode(["message" => "Incidencia registrada correctamente", "numero_incidente" => $nuevoNumeroIncidente, "id" => $stmt->insert_id]);
    } else {
        http_response_code(500);
        echo json_encode(["error" => "Error al insertar incidencia"]);
    }

    $stmt->close();
}

$conn->close();
?>
