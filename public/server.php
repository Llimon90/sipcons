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

if ($method === "GET" && isset($_GET["incidencias_abiertas"])) {
    // Consulta para obtener todas las incidencias abiertas
    $sql = "SELECT * FROM incidencias WHERE estatus = 'abierta'";
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

if ($method === "POST") {
    // Leer los datos enviados desde `fetch()`
    $data = json_decode(file_get_contents("php://input"), true);

    // Validar los datos
    if (!isset($data["numero"], $data["cliente"], $data["contacto"], $data["sucursal"], $data["fecha"], $data["tecnico"], $data["status"], $data["falla"])) {
        echo json_encode(["error" => "Todos los campos son obligatorios"]);
        exit();
    }

    // Obtener el último número de incidencia
    $sqlUltimoNumero = "SELECT numero_incidente FROM incidencias ORDER BY id DESC LIMIT 1";
    $result = $conn->query($sqlUltimoNumero);

    $nuevoNumeroIncidente = "SIP-0001"; // Valor inicial si no hay registros

    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $ultimoNumero = $row["numero_incidente"]; // "SIP-0001"
        $numeroIncremental = intval(explode("-", $ultimoNumero)[1]) + 1;
        $nuevoNumeroIncidente = "SIP-" . str_pad($numeroIncremental, 4, "0", STR_PAD_LEFT);
    }

    // Insertar la nueva incidencia
    $sql = "INSERT INTO incidencias (numero, cliente, contacto, sucursal, fecha, tecnico, estatus, falla, numero_incidente) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sssssssss", $data["numero"], $data["cliente"], $data["contacto"], $data["sucursal"], $data["fecha"], $data["tecnico"], $data["status"], $data["falla"], $nuevoNumeroIncidente);

    if ($stmt->execute()) {
        echo json_encode(["message" => "Incidencia registrada correctamente", "numero_incidente" => $nuevoNumeroIncidente, "id" => $stmt->insert_id]);
    } else {
        echo json_encode(["error" => "Error al insertar incidencia"]);
    }

    $stmt->close();
}

//BLOQUE PARA ENVIAR Y DAR DE ALTA USUARIOS Y TECNICOS

if ($method === "POST" && isset($_GET["usuarios"])) {
    // Leer datos del formulario
    $data = json_decode(file_get_contents("php://input"), true);

    // Validar campos obligatorios
    if (!isset($data["nombre"], $data["correo"], $data["telefono"], $data["usuario"], $data["password"], $data["rol"])) {
        echo json_encode(["error" => "Todos los campos son obligatorios"]);
        exit();
    }

    // Hashear la contraseña antes de guardarla
    $passwordHash = password_hash($data["password"], PASSWORD_DEFAULT);

    // Insertar usuario en la base de datos
    $sql = "INSERT INTO usuarios (nombre, correo, telefono, usuario, password, rol) VALUES (?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssssss", $data["nombre"], $data["correo"], $data["telefono"], $data["usuario"], $passwordHash, $data["rol"]);

    if ($stmt->execute()) {
        echo json_encode(["message" => "Usuario registrado correctamente"]);
    } else {
        echo json_encode(["error" => "Error al registrar usuario"]);
    }

    $stmt->close();
}

$conn->close();
?>