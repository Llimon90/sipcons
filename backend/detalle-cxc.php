<?php
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../auth/permisos.php';
requirePermiso('cuentas_por_cobrar');
header('Content-Type: application/json');

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Falta el id de la cuenta por cobrar.']);
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM cuentas_por_cobrar WHERE id = ?');
$stmt->execute([$id]);
$cxc = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$cxc) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Cuenta por cobrar no encontrada.']);
    exit;
}

$stmtPagos = $pdo->prepare('SELECT * FROM pagos_cxc WHERE cxc_id = ? ORDER BY fecha DESC, id DESC');
$stmtPagos->execute([$id]);
$cxc['pagos'] = $stmtPagos->fetchAll(PDO::FETCH_ASSOC);

echo json_encode(['success' => true, 'cxc' => $cxc]);
