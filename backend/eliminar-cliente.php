<?php
header('Content-Type: application/json');

$host = "localhost";
$user = "sipcons1_appweb";
$password = "sip*SYS2025";
$database = "sipcons1_appweb";

$conn = new mysqli($host, $user, $password, $database);

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

// Verificar si el cliente existe primero
$checkSql = "SELECT id FROM clientes WHERE id = ?";
$checkStmt = $conn->prepare($checkSql);
$checkStmt->bind_param("i", $id);
$checkStmt->execute();
$checkResult = $checkStmt->get_result();

if ($checkResult->num_rows === 0) {
    echo json_encode(['error' => 'Cliente no encontrado']);
    exit;
}

// Eliminar el cliente
$sql = "DELETE FROM clientes WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $id);

if ($stmt->execute()) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['error' => 'Error al eliminar el cliente']);
}

$stmt->close();
$conn->close();
?>