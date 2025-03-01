<?php
include 'conexion.php';

// Decodificar los datos recibidos en formato JSON
$data = json_decode(file_get_contents("php://input"), true);

// Verificar que el ID esté presente
if (!isset($data['id'])) {
    echo json_encode(["error" => "Falta el ID para eliminar"]);
    exit();
}

$sql = "DELETE FROM incidencias WHERE id=?";
$stmt = $conn->prepare($sql);

// Vincular el parámetro de ID
$stmt->bind_param("i", $data["id"]);

// Ejecutar la consulta y devolver el resultado
if ($stmt->execute()) {
    echo json_encode(["message" => "Incidencia eliminada"]);
} else {
    echo json_encode(["error" => "Error al eliminar"]);
}

$stmt->close();
$conn->close();
?>
