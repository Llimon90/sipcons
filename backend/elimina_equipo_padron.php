<?php
require_once __DIR__ . '/../auth/middleware.php';
header('Content-Type: application/json');

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
    error_log("elimina_equipo_padron.php: " . $e->getMessage());
    echo json_encode(['exito' => false, 'mensaje' => 'Error al eliminar el equipo']);
}
?>