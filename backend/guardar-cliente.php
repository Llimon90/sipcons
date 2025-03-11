<?php
// Conexión a la base de datos
$host = "localhost";
$user = "u179371012_root";
$password = "Llimon.2025";
$database = "u179371012_sipcons";

$conn = new mysqli($host, $user, $password, $database);

if ($conn->connect_error) {
    die(json_encode(['success' => false, 'message' => 'Error de conexión: ' . $conn->connect_error]));
}

// Recibe datos del formulario
$nombre = $_POST['nombre'] ?? '';
$rfc = $_POST['rfc'] ?? '';
$direccion = $_POST['direccion'] ?? '';
$telefono = $_POST['telefono'] ?? '';
$contactos = $_POST['contactos'] ?? '';
$email = $_POST['email'] ?? '';

if (empty($nombre) || empty($rfc) || empty($direccion) || empty($telefono) || empty($contactos) || empty($email)) {
    echo json_encode(['success' => false, 'message' => 'Todos los campos son obligatorios']);
    exit;
}

// Evitar inyecciones SQL
$nombre = $conn->real_escape_string($nombre);
$rfc = $conn->real_escape_string($rfc);
$direccion = $conn->real_escape_string($direccion);
$telefono = $conn->real_escape_string($telefono);
$contactos = $conn->real_escape_string($contactos);
$email = $conn->real_escape_string($email);

// Inserta en la base de datos
$sql = "INSERT INTO clientes (nombre, rfc, direccion, telefono, contactos, email) 
        VALUES ('$nombre', '$rfc', '$direccion', '$telefono', '$contactos', '$email')";

if ($conn->query($sql) === TRUE) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $conn->error]);
}

$conn->close();
