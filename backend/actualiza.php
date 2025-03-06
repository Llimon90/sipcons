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

// Leer los datos enviados desde el frontend
$data = json_decode(file_get_contents("php://input"), true);

if (!isset($data['id'], $data['cliente'], $data['contacto'], $data['sucursal'], $data['fecha'], $data['tecnico'], $data['estatus'], $data['falla'], $data['accion'])) {
    echo json_encode(["error" => "Faltan datos"]);
    exit();
}

// Actualizar la incidencia en la base de datos
$sql = "UPDATE incidencias SET numero = ?, cliente = ?, contacto = ?, sucursal = ?, fecha = ?, tecnico = ?, estatus = ?, falla = ?, accion = ? WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("sssssssssi", $data['numero'], $data['cliente'], $data['contacto'], $data['sucursal'], $data['fecha'], $data['tecnico'], $data['estatus'], $data['falla'], $data['accion'], $data['id']);

if ($stmt->execute()) {
    echo json_encode(["success" => true]);
} else {
    echo json_encode(["error" => "Error al actualizar la incidencia"]);
}

$stmt->close();
$conn->close();
?>
