<?php
// Configuración de conexión con la base de datos
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
    // Consulta para obtener todas las incidencias con los campos correctos
    $sql = "SELECT numero, cliente, contacto, sucursal, fecha, tecnico, estatus, falla, numero_incidente FROM incidencias";
    $result = $conn->query($sql);

    $incidencias = [];

    while ($row = $result->fetch_assoc()) {
        $incidencias[] = $row;
    }

    echo json_encode($incidencias ?: ["message" => "No hay incidencias registradas"]);
} elseif ($method === "POST") {
    // Leer los datos enviados desde fetch()
    $data = json_decode(file_get_contents("php://input"), true);

    // Validar los datos obligatorios
    if (!isset($data["numero"], $data["cliente"], $data["contacto"], $data["sucursal"], $data["fecha"], $data["tecnico"], $data["estatus"], $data["falla"])) {
        echo json_encode(["error" => "Todos los campos son obligatorios"]);
        exit();
    }

    // Obtener el último número de incidente
    $sqlUltimoNumero = "SELECT numero_incidente FROM incidencias ORDER BY id DESC LIMIT 1";
    $result = $conn->query($sqlUltimoNumero);

    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $ultimoNumero = $row["numero_incidente"];
        $numeroIncremental = intval(substr($ultimoNumero, 4)) + 1;
    } else {
        $numeroIncremental = 1;
    }

    $nuevoNumeroIncidente = "SIP-" . str_pad($numeroIncremental, 4, "0", STR_PAD_LEFT);

    // Insertar la nueva incidencia
    $sql = "INSERT INTO incidencias (numero, cliente, contacto, sucursal, fecha, tecnico, estatus, falla, numero_incidente) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param(
        "sssssssss",
        $data["numero"],
        $data["cliente"],
        $data["contacto"],
        $data["sucursal"],
        $data["fecha"],
        $data["tecnico"],
        $data["estatus"],  // Aquí corregí "status" por "estatus"
        $data["falla"],
        $nuevoNumeroIncidente
    );

    if ($stmt->execute()) {
        echo json_encode([
            "message" => "Incidencia registrada correctamente",
            "numero_incidente" => $nuevoNumeroIncidente,
            "id" => $stmt->insert_id
        ]);
    } else {
        echo json_encode(["error" => "Error al insertar incidencia: " . $stmt->error]);
    }

    $stmt->close();
}

$conn->close();
?>
