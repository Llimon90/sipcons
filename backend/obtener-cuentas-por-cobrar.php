<?php
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../auth/permisos.php';
require_once __DIR__ . '/lib/cxc_helpers.php';
requirePermiso('cuentas_por_cobrar');
header('Content-Type: application/json');

// Disparador al vuelo: marca 'Vencida' lo que corresponda antes de listar
// (mismo patrón que backend/generador_tickets.php, sin necesitar un cron).
$pdo->exec("UPDATE cuentas_por_cobrar SET estatus = 'Vencida'
            WHERE saldo_pendiente > 0 AND estatus NOT IN ('Pagada','Vencida') AND fecha_vencimiento < CURDATE()");

$clienteId = (int)($_GET['cliente_id'] ?? 0);
$estatus = trim((string)($_GET['estatus'] ?? ''));
$estatusValidos = ['Pendiente', 'Parcial', 'Pagada', 'Vencida'];

$sql = 'SELECT * FROM cuentas_por_cobrar WHERE 1=1';
$parametros = [];
if ($clienteId > 0) {
    $sql .= ' AND cliente_id = ?';
    $parametros[] = $clienteId;
}
if (in_array($estatus, $estatusValidos, true)) {
    $sql .= ' AND estatus = ?';
    $parametros[] = $estatus;
}
$sql .= ' ORDER BY fecha_vencimiento ASC';

$stmt = $pdo->prepare($sql);
$stmt->execute($parametros);
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
