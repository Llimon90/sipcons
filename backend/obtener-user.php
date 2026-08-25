<?php
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../auth/permisos.php';
requirePermiso('usuarios');
header('Content-Type: application/json');

try {
    $stmt = $conn->prepare("SELECT id, nombre, usuario, rol FROM usuarios ORDER BY nombre");
    $stmt->execute();
    $result = $stmt->get_result();

    $usuarios = [];
    while ($row = $result->fetch_assoc()) {
        $usuarios[] = $row;
    }

    echo json_encode(['success' => true, 'data' => $usuarios]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error al obtener los usuarios: ' . $e->getMessage()]);
}
?>
