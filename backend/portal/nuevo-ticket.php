<?php
// Alta de un ticket desde el portal de autoservicio. Mismo flujo transaccional
// y de folio que backend/server.php (alta de personal), pero el cliente y el
// contacto se toman SIEMPRE de la sesión del portal, nunca de lo que mande
// el formulario: un cliente del portal no puede abrir un ticket a nombre de
// otro cliente.
require_once __DIR__ . '/../../auth/portal_middleware.php';
require_once __DIR__ . '/../../auth/audit.php';
require_once __DIR__ . '/../lib/incidencias_helpers.php';
require_once __DIR__ . '/../lib/sla_helpers.php';
requirePortalAuth();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    die(json_encode(['success' => false, 'error' => 'Método no permitido']));
}

$clienteId = (int)$_SESSION['portal_cliente_id'];
$data = $_POST;

$falla = trim((string)($data['falla'] ?? ''));
$sucursal = trim((string)($data['sucursal'] ?? ''));
$categoria = trim((string)($data['categoria'] ?? ''));
$prioridad = prioridadValida($data['prioridad'] ?? null) ? $data['prioridad'] : PRIORIDAD_DEFAULT;

if ($falla === '') {
    http_response_code(400);
    die(json_encode(['success' => false, 'error' => 'Describe la falla para poder registrar el ticket.']));
}

$cliente = $pdo->prepare("SELECT nombre FROM clientes WHERE id = ? LIMIT 1");
$cliente->execute([$clienteId]);
$clienteNombre = $cliente->fetchColumn();
if (!$clienteNombre) {
    http_response_code(404);
    die(json_encode(['success' => false, 'error' => 'Cliente no encontrado']));
}

$revisionArchivos = revisarArchivosSubidos('archivos');
$limites = limitesSubida();
if ($rechazo = rechazoDeArchivos($revisionArchivos, $limites)) {
    http_response_code($rechazo[0]);
    die(json_encode(['success' => false, 'error' => $rechazo[2]]));
}

$archivosMovidos = [];
try {
    $pdo->beginTransaction();

    $ultimo = $pdo->query("SELECT numero_incidente FROM incidencias ORDER BY id DESC LIMIT 1 FOR UPDATE")->fetchColumn();
    $nuevoNumeroIncidente = "SIP-0001";
    if ($ultimo) {
        $numeroIncremental = intval(explode("-", $ultimo)[1] ?? 0) + 1;
        $nuevoNumeroIncidente = "SIP-" . str_pad($numeroIncremental, 4, "0", STR_PAD_LEFT);
    }

    $stmt = $pdo->prepare(
        "INSERT INTO incidencias (reporte_cliente, cliente, cliente_id, contacto, sucursal, categoria, prioridad, fecha, tecnico, estatus, falla, notas, numero_incidente)
         VALUES ('', ?, ?, ?, ?, ?, ?, NOW(), '', 'Abierto', ?, ?, ?)"
    );
    $stmt->execute([
        $clienteNombre,
        $clienteId,
        $_SESSION['portal_email'],
        $sucursal,
        $categoria,
        $prioridad,
        $falla,
        'Ticket abierto por el cliente desde el portal de autoservicio.',
        $nuevoNumeroIncidente,
    ]);
    $nuevoId = (int)$pdo->lastInsertId();

    $archivosAgregados = guardarArchivosIncidencia($pdo, $nuevoId, $revisionArchivos['validos'], $archivosMovidos);

    registrarAuditoria('incidencias', $nuevoId, 'CREATE', null, [
        'cliente' => $clienteNombre, 'cliente_id' => $clienteId, 'contacto' => $_SESSION['portal_email'],
        'sucursal' => $sucursal, 'categoria' => $categoria, 'prioridad' => $prioridad, 'falla' => $falla,
        'origen' => 'portal_autoservicio',
    ], $nuevoNumeroIncidente);

    $pdo->commit();
    echo json_encode(['success' => true, 'numero_incidente' => $nuevoNumeroIncidente, 'id' => $nuevoId]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    foreach ($archivosMovidos as $ruta) @unlink($ruta);
    error_log('portal/nuevo-ticket.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'No se pudo registrar el ticket. Intenta de nuevo.']);
}
