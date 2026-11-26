<?php
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../auth/permisos.php';
requirePermiso('cotizaciones');
header('Content-Type: application/json');

$filtro = trim((string)($_GET['q'] ?? ''));
$estatus = trim((string)($_GET['estatus'] ?? ''));

$sql = 'SELECT * FROM cotizaciones WHERE (folio LIKE ? OR cliente LIKE ?)';
$parametros = ["%$filtro%", "%$filtro%"];

$estatusValidos = ['Pendiente', 'Aprobada', 'Rechazada', 'Convertida', 'Vencida'];
if (in_array($estatus, $estatusValidos, true)) {
    $sql .= ' AND estatus = ?';
    $parametros[] = $estatus;
}
$sql .= ' ORDER BY id DESC LIMIT 500';

$stmt = $pdo->prepare($sql);
$stmt->execute($parametros);
$cotizaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Marca como 'Vencida' (solo en la respuesta, no en BD) las pendientes cuyo
// plazo ya pasó, para que la lista se vea correcta sin depender de un cron.
// El cambio real a BD se hace la próxima vez que se guarde/consulte el detalle.
$hoy = date('Y-m-d');
foreach ($cotizaciones as &$c) {
    if ($c['estatus'] === 'Pendiente' && $c['fecha_vigencia'] && $c['fecha_vigencia'] < $hoy) {
        $c['estatus'] = 'Vencida';
    }
}

echo json_encode($cotizaciones);
