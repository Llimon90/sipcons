<?php
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../auth/permisos.php';
requirePermiso('usuarios');
header('Content-Type: application/json');

try {
    $empresaId = (int)$_SESSION['empresa_id'];
    $stmt = $conn->prepare("SELECT id, nombre, usuario, rol FROM usuarios WHERE empresa_id = ? ORDER BY nombre");
    $stmt->bind_param("i", $empresaId);
    $stmt->execute();
    $result = $stmt->get_result();

    $usuarios = [];
    while ($row = $result->fetch_assoc()) {
        $usuarios[] = $row;
    }

    echo json_encode(['success' => true, 'data' => $usuarios]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error al obtener los usuarios: ' . mensajeErrorPublico($e)]);
}
?>
