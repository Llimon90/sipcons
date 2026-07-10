<?php
require_once __DIR__ . '/../auth/middleware.php';
requireRole('Administrador');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'DELETE' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit;
}

if (isset($_GET['id']) && ctype_digit((string)$_GET['id'])) {
    $id = (int)$_GET['id'];

    if ($id === (int)($_SESSION['user_id'] ?? 0)) {
        echo json_encode(['success' => false, 'message' => 'No puedes eliminar tu propio usuario.']);
        exit;
    }

    try {
        $stmt = $conn->prepare("DELETE FROM usuarios WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();

        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        error_log('eliminar_usuario: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Error al eliminar el usuario']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'ID de usuario no proporcionado.']);
}
?>
