<?php
require_once __DIR__ . '/../auth/middleware.php';
requireRole('Administrador');
header('Content-Type: application/json');

// Configuración de la base de datos

try {
    // Verificar la conexión
    if ($conn->connect_error) {
        error_log("alta-user.php: Error de conexión: " . $conn->connect_error);
        echo json_encode(['error' => 'Error de conexión con el servidor']);
        exit;
    }

    // Obtener y sanitizar los datos del formulario
    $nombre = trim($_POST['nombre'] ?? '');
    $correo = trim($_POST['correo'] ?? '');
    $telefono = trim($_POST['telefono'] ?? '');
    $usuario = trim($_POST['usuario'] ?? '');
    $password = $_POST['password'] ?? '';
    $rol = $_POST['rol'] ?? '';

    // Validar que todos los campos estén completos
    if (empty($nombre) || empty($correo) || empty($telefono) || empty($usuario) || empty($password) || empty($rol)) {
        echo json_encode(['success' => false, 'message' => 'Todos los campos son obligatorios']);
        exit;
    }

    // Encriptar la contraseña
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

    // Preparar la consulta
    $stmt = $conn->prepare("INSERT INTO usuarios (nombre, correo, telefono, usuario, password, rol) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("ssssss", $nombre, $correo, $telefono, $usuario, $hashedPassword, $rol);

    // Ejecutar la consulta
    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        error_log("alta-user.php: Error al insertar los datos: " . $stmt->error);
        echo json_encode(['success' => false, 'message' => 'Error al insertar los datos']);
    }

    // Cerrar la conexión
    $stmt->close();
    $conn->close();
} catch (Exception $e) {
    error_log("alta-user.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error al procesar la solicitud']);
}
?>
