<?php
include 'server.php';

$data = json_decode(file_get_contents("php://input"), true);

$sql = "DELETE FROM incidencias WHERE id=?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $data["id"]);

echo $stmt->execute() ? json_encode(["message" => "Incidencia eliminada"]) : json_encode(["error" => "Error al eliminar"]);
?>
