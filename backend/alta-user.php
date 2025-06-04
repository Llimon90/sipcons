<?php
header('Content-Type: application/json');
// Configurar cabeceras para permitir acceso desde el frontend
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

// Configuración de la base de datos
require_once 'conexion.php';

try {
    // Verificar la conexión
    if ($conn->connect_error) {
        echo json_encode(['error' => 'Error de conexión: ' . $conn->connect_error]);
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
        echo json_encode(['success' => false, 'message' => 'Error al insertar los datos: ' . $stmt->error]);
    }

    // Cerrar la conexión
    $stmt->close();
    $conn->close();
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Excepción capturada: ' . $e->getMessage()]);
}
?>
