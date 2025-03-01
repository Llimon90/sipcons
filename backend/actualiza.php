<?php
include 'server.php'; // Archivo que contiene la conexión a la BD

$data = json_decode(file_get_contents("php://input"), true);

$sql = "UPDATE incidencias SET cliente=?, sucursal=?, contacto=?, falla=?, fecha=?, tecnico=?, estatus=? WHERE id=?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("sssssssi", $data["cliente"], $data["sucursal"], $data["contacto"], $data["falla"], $data["fecha"], $data["tecnico"], $data["estatus"], $data["id"]);

echo $stmt->execute() ? json_encode(["message" => "Incidencia actualizada"]) : json_encode(["error" => "Error al actualizar"]);
?>
