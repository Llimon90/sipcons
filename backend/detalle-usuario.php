<?php
header('Content-Type: application/json');
require_once 'conexion.php';

$id = $_GET['id'] ?? null;

if (!$id) {
    echo json_encode(['error' => 'ID de usuario no proporcionado']);
    exit;
}

try {
    $stmt = $conn->prepare("SELECT id, nombre, correo, telefono, usuario, rol FROM usuarios WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $usuario = $result->fetch_assoc();
        echo json_encode($usuario);
    } else {
        echo json_encode(['error' => 'Usuario no encontrado']);
    }
} catch (Exception $e) {
    echo json_encode(['error' => 'Error al obtener usuario: ' . $e->getMessage()]);
}
?>