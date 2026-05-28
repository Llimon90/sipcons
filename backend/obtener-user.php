<?php
header('Content-Type: application/json');
require_once 'conexion.php';

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
