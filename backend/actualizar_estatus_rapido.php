<?php
// Corrección rápida de estatus desde el panel de estadísticas (drill-down
// del gráfico "Incidencias por Estatus"): permite arreglar un folio con el
// estatus mal capturado sin abrir el formulario completo de edición.
// Solo toca la columna `estatus` y queda registrado en `auditoria`, igual
// que cualquier otra edición de incidencia.
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../auth/permisos.php';
require_once __DIR__ . '/../auth/audit.php';

header('Content-Type: application/json');

requirePermiso('incidencias');

const ESTADOS_VALIDOS = ['Abierto', 'Asignado', 'Pendiente', 'Completado', 'Cerrado con factura', 'Cerrado sin factura'];
const ESTADOS_RESTRINGIDOS_TECNICO = ['Cerrado con factura', 'Cerrado sin factura'];

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$id = isset($input['id']) ? (int)$input['id'] : 0;
$nuevoEstatus = trim($input['estatus'] ?? '');

if ($id <= 0 || $nuevoEstatus === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Faltan datos (id/estatus).']);
    exit;
}

if (!in_array($nuevoEstatus, ESTADOS_VALIDOS, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Estatus no válido.']);
    exit;
}

if (($_SESSION['rol'] ?? '') === 'Técnico' && in_array($nuevoEstatus, ESTADOS_RESTRINGIDOS_TECNICO, true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Tu rol no tiene permiso para cerrar incidencias con/sin factura.']);
    exit;
}

$stmtAnterior = $conn->prepare("SELECT * FROM incidencias WHERE id = ?");
$stmtAnterior->bind_param("i", $id);
$stmtAnterior->execute();
$anterior = $stmtAnterior->get_result()->fetch_assoc();
$stmtAnterior->close();

if (!$anterior) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Incidencia no encontrada.']);
    exit;
}

if ($anterior['estatus'] === $nuevoEstatus) {
    echo json_encode(['success' => true, 'sin_cambios' => true]);
    exit;
}

$stmt = $conn->prepare("UPDATE incidencias SET estatus = ? WHERE id = ?");
$stmt->bind_param("si", $nuevoEstatus, $id);

if ($stmt->execute()) {
    $datosNuevos = $anterior;
    $datosNuevos['estatus'] = $nuevoEstatus;
    registrarAuditoria('incidencias', $id, 'UPDATE', $anterior, $datosNuevos, $anterior['numero_incidente'] ?? null);
    echo json_encode(['success' => true]);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'No se pudo actualizar el estatus.']);
}

$stmt->close();
$conn->close();
