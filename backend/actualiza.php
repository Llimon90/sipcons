<?php
include 'conexion.php'; // Archivo con la conexión a la BD

// Decodificar los datos recibidos en formato JSON
$data = json_decode(file_get_contents("php://input"), true);

// Verificar que los datos necesarios existan
if (!isset($data['id'], $data['cliente'], $data['sucursal'], $data['contacto'], $data['falla'], $data['fecha'], $data['tecnico'], $data['estatus'])) {
    echo json_encode(["error" => "Faltan datos para actualizar"]);
    exit();
}

$sql = "UPDATE incidencias SET cliente=?, sucursal=?, contacto=?, falla=?, fecha=?, tecnico=?, estatus=? WHERE id=?";
$stmt = $conn->prepare($sql);

// Enlace de los parámetros a los valores
$stmt->bind_param("sssssssi", $data["cliente"], $data["sucursal"], $data["contacto"], $data["falla"], $data["fecha"], $data["tecnico"], $data["estatus"], $data["id"]);

// Ejecutar la consulta y devolver el resultado
if ($stmt->execute()) {
    echo json_encode(["message" => "Incidencia actualizada"]);
} else {
    echo json_encode(["error" => "Error al actualizar"]);
}

$stmt->close();
$conn->close();
?>
