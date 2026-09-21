<?php
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../auth/audit.php';
require_once __DIR__ . '/../config/contactos_cliente.php';
header('Content-Type: application/json');


$conn = new mysqli($host, $user, $password, $database);

if ($conn->connect_error) {
    die(json_encode(["error" => "Error de conexión: " . $conn->connect_error]));
}

$id = $_POST['id'];
$nombre = $_POST['nombre'];
$rfc = $_POST['rfc'];
$direccion = $_POST['direccion'];
$telefono = $_POST['telefono'];
$email = $_POST['email'];

// Contactos: lista JSON [{nombre, telefono, email}] (o texto "Juan; Pedro").
$listaContactos = isset($_POST['contactos_json'])
    ? normalizarContactos($_POST['contactos_json'])
    : normalizarContactos(array_map(fn($n) => ['nombre' => $n], array_filter(array_map('trim', explode(';', $_POST['contactos'] ?? '')))));
$contactos = contactosComoTexto($listaContactos);

// Estado anterior para el historial
$stmtAnterior = $conn->prepare("SELECT nombre, rfc, direccion, telefono, contactos, email FROM clientes WHERE id = ?");
$stmtAnterior->bind_param("i", $id);
$stmtAnterior->execute();
$anterior = $stmtAnterior->get_result()->fetch_assoc();
$stmtAnterior->close();
if ($anterior) {
    $anterior['contactos_detalle'] = obtenerContactosCliente((int)$id);
}

$sql = "UPDATE clientes SET nombre = ?, rfc = ?, direccion = ?, telefono = ?, contactos = ?, email = ? WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ssssssi", $nombre, $rfc, $direccion, $telefono, $contactos, $email, $id);

if ($stmt->execute()) {
    sincronizarContactosCliente((int)$id, $listaContactos);
    registrarAuditoria('clientes', $id, 'UPDATE', $anterior ?: null, [
        'nombre'    => $nombre,
        'rfc'       => $rfc,
        'direccion' => $direccion,
        'telefono'  => $telefono,
        'contactos' => $contactos,
        'contactos_detalle' => $listaContactos,
        'email'     => $email,
    ]);
    echo json_encode(["success" => true]);
} else {
    echo json_encode(["error" => "Error al actualizar el cliente"]);
}

$stmt->close();
$conn->close();
?>