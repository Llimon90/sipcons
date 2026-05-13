<?php
header('Content-Type: application/json');
require_once 'conexion.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
        throw new Exception("Método no permitido");
    }

    $id = $_GET['id'] ?? throw new Exception("ID de equipo no especificado");

    $sql = "DELETE FROM padron_equipos WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id]);

    echo json_encode(['exito' => true, 'mensaje' => 'Equipo eliminado correctamente.']);
} catch (Exception $e) {
    echo json_encode(['exito' => false, 'mensaje' => $e->getMessage()]);
}
?>