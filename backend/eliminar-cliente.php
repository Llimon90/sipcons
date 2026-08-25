<?php
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../auth/audit.php';
header('Content-Type: application/json');


if ($conn->connect_error) {
    echo json_encode(['error' => 'Error de conexión: ' . $conn->connect_error]);
    exit;
}

// Solo permitimos método DELETE
if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    echo json_encode(['error' => 'Método no permitido']);
    exit;
}

$id = $_GET['id'] ?? null;

if (!$id) {
    echo json_encode(['error' => 'ID de cliente no proporcionado']);
    exit;
}

// Verificar si el cliente existe primero (y capturar su estado para el historial)
$checkSql = "SELECT nombre, rfc, direccion, telefono, contactos, email FROM clientes WHERE id = ?";
$checkStmt = $conn->prepare($checkSql);
$checkStmt->bind_param("i", $id);
$checkStmt->execute();
$anterior = $checkStmt->get_result()->fetch_assoc();

if (!$anterior) {
    echo json_encode(['error' => 'Cliente no encontrado']);
    exit;
}

// Eliminar el cliente
$sql = "DELETE FROM clientes WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $id);

if ($stmt->execute()) {
    registrarAuditoria('clientes', $id, 'DELETE', $anterior, null);
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['error' => 'Error al eliminar el cliente']);
}

$stmt->close();
$conn->close();
?>