<?php
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../auth/audit.php';
require_once __DIR__ . '/../config/contactos_cliente.php';
// Conexión a la base de datos

if ($conn->connect_error) {
    die(json_encode(['success' => false, 'message' => 'Error de conexión: ' . $conn->connect_error]));
}


// Permitir solicitudes desde el frontend
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

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
$email = $_POST['email'] ?? '';

// Contactos: lista JSON [{nombre, telefono, email}] (o, por compatibilidad,
// el texto "Juan; Pedro"). clientes.contactos guarda solo los nombres.
$listaContactos = isset($_POST['contactos_json'])
    ? normalizarContactos($_POST['contactos_json'])
    : normalizarContactos(array_map(fn($n) => ['nombre' => $n], array_filter(array_map('trim', explode(';', $_POST['contactos'] ?? '')))));
$contactos = contactosComoTexto($listaContactos);

if (empty($nombre) || empty($contactos) ) {
    echo json_encode(['success' => false, 'message' => 'Nombre y contacto son obligatorios']);
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
    $nuevoId = $conn->insert_id;
    sincronizarContactosCliente($nuevoId, $listaContactos);
    registrarAuditoria('clientes', $nuevoId, 'CREATE', null, [
        'nombre'    => $nombre,
        'rfc'       => $rfc,
        'direccion' => $direccion,
        'telefono'  => $telefono,
        'contactos' => $contactos,
        'contactos_detalle' => $listaContactos,
        'email'     => $email,
    ]);
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $conn->error]);
}

$conn->close();
