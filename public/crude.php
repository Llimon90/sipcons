<?php
// Configurar conexión con la base de datos
$host = "localhost";
$user = "u179371012_root";
$password = "Llimon.2025";
$database = "u179371012_sipcons";

$conn = new mysqli($host, $user, $password, $database);

// Verificar la conexión
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Obtener los datos del formulario
$data = json_decode(file_get_contents("php://input"), true);

// Verificar que se hayan enviado los datos necesarios
if (isset($data['id']) && isset($data['numero']) && isset($data['cliente'])) {
    $id = $data['id'];
    $numero = $data['numero'];
    $cliente = $data['cliente'];
    $contacto = $data['contacto'];
    $sucursal = $data['sucursal'];
    $falla = $data['falla'];
    $fecha = $data['fecha'];
    $tecnico = $data['tecnico'];
    $estatus = $data['estatus'];

    // Preparar la consulta SQL para actualizar la incidencia
    $sql = "UPDATE incidencias SET 
                numero = ?, 
                cliente = ?, 
                contacto = ?, 
                sucursal = ?, 
                falla = ?, 
                fecha = ?, 
                tecnico = ?, 
                estatus = ? 
            WHERE id = ?";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssssssssi", $numero, $cliente, $contacto, $sucursal, $falla, $fecha, $tecnico, $estatus, $id);

    // Ejecutar la consulta
    if ($stmt->execute()) {
        echo json_encode(["success" => true]);
    } else {
        echo json_encode(["success" => false, "error" => "Error al actualizar incidencia: " . $stmt->error]);
    }

    $stmt->close();
} else {
    echo json_encode(["success" => false, "error" => "Datos incompletos"]);
}

$conn->close();
?>