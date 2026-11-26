<?php
// Detalle de solo lectura de un ticket propio. Si el id no es de un ticket
// del cliente en sesión, responde 404 (no 403): no se confirma que el
// ticket exista si es de otro cliente.
require_once __DIR__ . '/../../auth/portal_middleware.php';
requirePortalAuth();

$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$id) {
    http_response_code(404);
    die(json_encode(['success' => false, 'error' => 'Ticket no encontrado']));
}

$clienteId = (int)$_SESSION['portal_cliente_id'];

// Solo se exponen los campos que le sirven al cliente: nada de notas
// internas de técnicos ni datos de otros módulos.
$stmt = $pdo->prepare("SELECT id, numero_incidente, reporte_cliente, sucursal, categoria, prioridad, estatus, falla, fecha
    FROM incidencias WHERE id = ? AND cliente_id = ? LIMIT 1");
$stmt->execute([$id, $clienteId]);
$ticket = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$ticket) {
    http_response_code(404);
    die(json_encode(['success' => false, 'error' => 'Ticket no encontrado']));
}

$archivos = $pdo->prepare("SELECT ruta_archivo FROM archivos_incidencias WHERE incidencia_id = ?");
$archivos->execute([$id]);
$ticket['archivos'] = $archivos->fetchAll(PDO::FETCH_COLUMN);

echo json_encode(['success' => true, 'ticket' => $ticket], JSON_UNESCAPED_UNICODE);
