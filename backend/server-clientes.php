<?php


header("Content-Type: application/json");
$response = ["success" => false, "message" => ""];

// Configurar conexión con la base de datos
$host = "localhost";
$user = "u179371012_root";
$password = "Llimon.2025";
$database = "u179371012_sipcons";

$conn = new mysqli($host, $user, $password, $dbname);

// Verificar conexión
if ($conn->connect_error) {
    $response["message"] = "Error en la conexión: " . $conn->connect_error;
    echo json_encode($response);
    exit;
}

// Recibir los datos del formulario
$nombre = $_POST["nombre"] ?? "";
$rfc = $_POST["rfc"] ?? "";
$direccion = $_POST["direccion"] ?? "";
$telefono = $_POST["telefono"] ?? "";
$contactos = $_POST["contactos"] ?? "";
$email = $_POST["email"] ?? "";

// Validar que no estén vacíos
if (empty($nombre) || empty($rfc) || empty($direccion) || empty($telefono) || empty($contactos) || empty($email)) {
    $response["message"] = "Todos los campos son obligatorios.";
    echo json_encode($response);
    exit;
}

// Insertar en la base de datos
$sql = "INSERT INTO clientes (nombre, rfc, direccion, telefono, contactos, email) VALUES (?, ?, ?, ?, ?, ?)";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ssssss", $nombre, $rfc, $direccion, $telefono, $contactos, $email);

if ($stmt->execute()) {
    $response["success"] = true;
    $response["message"] = "Cliente registrado con éxito";
} else {
    $response["message"] = "Error al registrar cliente: " . $conn->error;
}

// Cerrar conexión
$stmt->close();
$conn->close();

echo json_encode($response);
?>
