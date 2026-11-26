<?php
// Alta de una solicitud de servicio desde el portal. El cliente_id SIEMPRE
// se toma de la sesión del portal, nunca de lo que mande el formulario.
require_once __DIR__ . '/../../auth/portal_middleware.php';
require_once __DIR__ . '/../../auth/audit.php';
requirePortalAuth();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    die(json_encode(['success' => false, 'error' => 'Método no permitido']));
}

$data = json_decode(file_get_contents('php://input'), true) ?: [];
$servicioId = (int)($data['servicio_id'] ?? 0);
$sucursal = trim((string)($data['sucursal'] ?? ''));
$descripcion = trim((string)($data['descripcion'] ?? ''));
$clienteId = (int)$_SESSION['portal_cliente_id'];

if ($servicioId <= 0) {
    http_response_code(422);
    die(json_encode(['success' => false, 'error' => 'Elige un servicio del catálogo.']));
}

try {
    $servicio = $pdo->prepare('SELECT id FROM catalogo_servicios WHERE id = ? AND activo = 1');
    $servicio->execute([$servicioId]);
    if (!$servicio->fetchColumn()) {
        http_response_code(404);
        die(json_encode(['success' => false, 'error' => 'El servicio no está disponible.']));
    }

    $cliente = $pdo->prepare('SELECT nombre FROM clientes WHERE id = ?');
    $cliente->execute([$clienteId]);
    $clienteNombre = $cliente->fetchColumn();
    if (!$clienteNombre) {
        http_response_code(404);
        die(json_encode(['success' => false, 'error' => 'Cliente no encontrado']));
    }

    $pdo->beginTransaction();

    $ultimo = $pdo->query("SELECT folio FROM solicitudes_servicio ORDER BY id DESC LIMIT 1 FOR UPDATE")->fetchColumn();
    $folio = 'SOL-' . str_pad($ultimo ? (int)substr($ultimo, 4) + 1 : 1, 5, '0', STR_PAD_LEFT);

    $pdo->prepare(
        'INSERT INTO solicitudes_servicio (folio, servicio_id, cliente_id, cliente, sucursal, descripcion, estatus, creado_por_portal, fecha)
         VALUES (?, ?, ?, ?, ?, ?, \'Pendiente\', 1, CURDATE())'
    )->execute([$folio, $servicioId, $clienteId, $clienteNombre, $sucursal !== '' ? $sucursal : null, $descripcion !== '' ? $descripcion : null]);
    $id = (int)$pdo->lastInsertId();

    $pdo->commit();

    registrarAuditoria('solicitudes_servicio', $id, 'CREATE', null, [
        'cliente' => $clienteNombre, 'cliente_id' => $clienteId, 'servicio_id' => $servicioId, 'origen' => 'portal_autoservicio',
    ], $folio);

    echo json_encode(['success' => true, 'folio' => $folio, 'id' => $id]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('portal/nueva-solicitud-servicio.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'No se pudo registrar la solicitud. Intenta de nuevo.']);
}
