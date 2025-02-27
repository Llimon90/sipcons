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
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_POST['id'];
    $numero = $_POST['numero'];
    $cliente = $_POST['cliente'];
    $contacto = $_POST['contacto'];
    $sucursal = $_POST['sucursal'];
    $falla = $_POST['falla'];
    $fecha = $_POST['fecha'];
    $tecnico = $_POST['tecnico'];
    $estatus = $_POST['estatus'];

    // Aquí deberías tener la lógica para actualizar la incidencia en la base de datos
    $resultado = actualizarIncidencia($id, $numero, $cliente, $contacto, $sucursal, $falla, $fecha, $tecnico, $estatus);
    
    if ($resultado) {
        echo json_encode(["success" => true]);
    } else {
        echo json_encode(["success" => false, "error" => "Error al actualizar"]);
    }
}
?>
