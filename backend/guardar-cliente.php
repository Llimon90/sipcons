<?php
require_once __DIR__ . '/../auth/middleware.php';
// Conexión a la base de datos

if ($conn->connect_error) {
    error_log("guardar-cliente.php: Error de conexión: " . $conn->connect_error);
    die(json_encode(['success' => false, 'message' => 'Error de conexión con el servidor']));
}


header("Content-Type: application/json");

// Asegurar que el contenido devuelto sea JSON
header('Content-Type: application/json');

// Evitar que se muestren errores o advertencias PHP
error_reporting(0);
ini_set('display_errors', 0);




// Recibe datos del formulario
$nombre = $_POST['nombre'] ?? '';
$rfc = $_POST['rfc'] ?? '';
$direccion = $_POST['direccion'] ?? '';
$telefono = $_POST['telefono'] ?? '';
$contactos = $_POST['contactos'] ?? '';
$email = $_POST['email'] ?? '';

if (empty($nombre) || empty($contactos) ) {
    echo json_encode(['success' => false, 'message' => 'Nombre y contacto son obligatorios']);
    exit;
}

// Inserta en la base de datos
$sql = "INSERT INTO clientes (nombre, rfc, direccion, telefono, contactos, email) VALUES (?, ?, ?, ?, ?, ?)";
$stmt = $conn->prepare($sql);
$stmt->bind_param('ssssss', $nombre, $rfc, $direccion, $telefono, $contactos, $email);

if ($stmt->execute()) {
    echo json_encode(['success' => true]);
} else {
    error_log("guardar-cliente.php: " . $stmt->error);
    echo json_encode(['success' => false, 'message' => 'Error al guardar el cliente']);
}
$stmt->close();

$conn->close();
