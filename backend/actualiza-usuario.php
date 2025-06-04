<?php
header('Content-Type: application/json');
require_once 'conexion.php';

$data = json_decode(file_get_contents('php://input'), true);

try {
    $id = $data['id'];
    $nombre = $data['nombre'];
    $correo = $data['correo'];
    $telefono = $data['telefono'];
    $usuario = $data['usuario'];
    $rol = $data['rol'];
    
    // Preparar la consulta base
    $sql = "UPDATE usuarios SET nombre = ?, correo = ?, telefono = ?, usuario = ?, rol = ?";
    $params = [$nombre, $correo, $telefono, $usuario, $rol];
    $types = "sssss";
    
    // Si se proporcionó una nueva contraseña
    if (!empty($data['password'])) {
        $hashedPassword = password_hash($data['password'], PASSWORD_DEFAULT);
        $sql .= ", password = ?";
        $params[] = $hashedPassword;
        $types .= "s";
    }
    
    $sql .= " WHERE id = ?";
    $params[] = $id;
    $types .= "i";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Error al actualizar usuario']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>