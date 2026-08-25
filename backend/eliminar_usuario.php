<?php
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../auth/audit.php';
requireRole('Administrador');
header('Content-Type: application/json');

if (isset($_GET['id'])) {
    $id = $_GET['id'];

    try {
        // Estado anterior para el historial (nunca se incluye la contraseña)
        $stmtAnterior = $conn->prepare("SELECT nombre, correo, telefono, usuario, rol FROM usuarios WHERE id = ?");
        $stmtAnterior->bind_param("i", $id);
        $stmtAnterior->execute();
        $anterior = $stmtAnterior->get_result()->fetch_assoc();
        $stmtAnterior->close();

        $stmt = $conn->prepare("DELETE FROM usuarios WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();

        registrarAuditoria('usuarios', $id, 'DELETE', $anterior ?: null, null);

        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error al eliminar el usuario: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'ID de usuario no proporcionado.']);
}
?>
