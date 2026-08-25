<?php
// ==============================================
// 1. Dependencias Críticas
// ==============================================
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../auth/audit.php';
require_once __DIR__ . '/../auth/permisos.php';
requirePermiso('usuarios');
require_once __DIR__ . '/../config/database.php'; // <-- Agregada la conexión a la BD

header('Content-Type: application/json');
ini_set('display_errors', 0);

$data = json_decode(file_get_contents('php://input'), true);

// Verificar que llegaron datos válidos
if (!$data || !isset($data['id'])) {
    http_response_code(400);
    die(json_encode(['success' => false, 'message' => 'Datos incompletos o inválidos']));
}

try {
    $id = $data['id'];
    $nombre = $data['nombre'];
    $correo = $data['correo'];
    $telefono = $data['telefono'];
    $usuario = $data['usuario'];
    $rol = $data['rol'];

    // Estado anterior para el historial (nunca se incluye la contraseña)
    $stmtAnterior = $conn->prepare("SELECT nombre, correo, telefono, usuario, rol FROM usuarios WHERE id = ?");
    $stmtAnterior->bind_param("i", $id);
    $stmtAnterior->execute();
    $anterior = $stmtAnterior->get_result()->fetch_assoc();
    $stmtAnterior->close();

    // Preparar la consulta base (usando tu objeto $conn de mysqli que viene en database.php)
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
    
    if (!$stmt) {
        throw new Exception("Error al preparar la consulta: " . $conn->error);
    }
    
    $stmt->bind_param($types, ...$params);
    
    if ($stmt->execute()) {
        registrarAuditoria('usuarios', $id, 'UPDATE', $anterior ?: null, [
            'nombre'            => $nombre,
            'correo'            => $correo,
            'telefono'          => $telefono,
            'usuario'           => $usuario,
            'rol'               => $rol,
            'password_cambiada' => !empty($data['password']),
        ]);
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Error al ejecutar la actualización']);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error del servidor: ' . $e->getMessage()]);
}
?>