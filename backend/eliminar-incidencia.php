<?php
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../auth/permisos.php';
require_once __DIR__ . '/../auth/audit.php';
header('Content-Type: application/json');

// Solo roles administrativos pueden borrar incidencias. Chequeo de rol fijo
// (no depende de la tabla permisos_rol, igual que requireGestionPrivilegios).
$rolesAdmin = ['Administrador', 'Técnico/Administrador', ROL_ACCESO_TOTAL];
if (!in_array($_SESSION['rol'] ?? '', $rolesAdmin, true)) {
    http_response_code(403);
    die(json_encode(['success' => false, 'error' => 'Solo un administrador puede eliminar incidencias']));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die(json_encode(['success' => false, 'error' => 'Método no permitido']));
}

if ($conn->connect_error) {
    die(json_encode(['success' => false, 'error' => 'Error de conexión']));
}

$data = json_decode(file_get_contents('php://input'), true);
$id = isset($data['id']) ? (int)$data['id'] : 0;

if ($id <= 0) {
    http_response_code(400);
    die(json_encode(['success' => false, 'error' => 'ID de incidencia inválido']));
}

// Estado completo antes de borrar: es lo único que queda en el historial.
$stmt = $conn->prepare("SELECT * FROM incidencias WHERE id = ?");
$stmt->bind_param('i', $id);
$stmt->execute();
$anterior = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$anterior) {
    http_response_code(404);
    die(json_encode(['success' => false, 'error' => 'Incidencia no encontrada']));
}

$stmt = $conn->prepare("SELECT ruta_archivo FROM archivos_incidencias WHERE incidencia_id = ?");
$stmt->bind_param('i', $id);
$stmt->execute();
$anterior['archivos'] = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'ruta_archivo');
$stmt->close();

$conn->begin_transaction();
try {
    // Hijos primero para no chocar con llaves foráneas. Los archivos físicos
    // en /uploads NO se borran: su nombre queda registrado en el historial.
    $stmt = $conn->prepare("DELETE FROM archivos_incidencias WHERE incidencia_id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare("DELETE FROM incidencias WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();

    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    error_log('Error al eliminar incidencia ' . $id . ': ' . $e->getMessage());
    http_response_code(500);
    die(json_encode(['success' => false, 'error' => 'No se pudo eliminar la incidencia']));
}

registrarAuditoria('incidencias', $id, 'DELETE', $anterior, null, $anterior['numero_incidente'] ?? null);

echo json_encode(['success' => true]);
$conn->close();
