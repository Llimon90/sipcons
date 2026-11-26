<?php
// Vincula a mano una incidencia con un cliente real (cliente_id), para los
// casos que la migración 017 no pudo emparejar automáticamente por nombre
// (p. ej. el campo "cliente" tiene una variante del nombre o una errata).
// Sin este vínculo, la incidencia simplemente no aparece en el portal de
// autoservicio; el uso normal de personal no se ve afectado.
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../auth/permisos.php';
require_once __DIR__ . '/../auth/audit.php';
requirePermiso('clientes');

header('Content-Type: application/json');

$incidenciaId = filter_var($_POST['incidencia_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$clienteId = filter_var($_POST['cliente_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$incidenciaId || !$clienteId) {
    http_response_code(400);
    die(json_encode(['success' => false, 'error' => 'Datos inválidos']));
}

$stmt = $pdo->prepare("UPDATE incidencias SET cliente_id = ? WHERE id = ?");
$stmt->execute([$clienteId, $incidenciaId]);

registrarAuditoria('incidencias', $incidenciaId, 'VINCULAR_CLIENTE', null, ['cliente_id' => $clienteId]);
echo json_encode(['success' => true]);
