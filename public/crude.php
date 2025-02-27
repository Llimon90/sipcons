<?php
// Configurar conexión con la base de datos (similar a server.php)
$host = "localhost";
$user = "u179371012_root";
$password = "Llimon.2025";
$database = "u179371012_sipcons";

$conn = new mysqli($host, $user, $password, $database);

if ($conn->connect_error) {
    die(json_encode(["error" => "Error de conexión: " . $conn->connect_error]));
}

// Leer los datos enviados desde el formulario

$cliente = $_POST['cliente'];
$contacto = $_POST['contacto'];
$sucursal = $_POST['sucursal'];
$falla = $_POST['falla'];
$fecha = $_POST['fecha'];
$tecnico = $_POST['tecnico'];
$estatus = $_POST['estatus'];

// Actualizar la incidencia en la base de datos
$sql = "UPDATE incidencias SET numero, cliente=?, contacto=?, sucursal=?, falla=?, fecha=?, tecnico=?, estatus=?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("sssssss", $cliente, $contacto, $sucursal, $falla, $fecha, $tecnico, $estatus);

if ($stmt->execute()) {
    echo json_encode(["success" => true]);
} else {
    echo json_encode(["error" => "Error al actualizar la incidencia"]);
}

$stmt->close();
$conn->close();
?>
