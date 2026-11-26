<?php
// Resultados agregados de CSAT para el personal (promedio y distribución 1-5).
// UI pendiente: no se integró todavía a informes.html por tiempo, pero el
// endpoint ya funciona.
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../auth/permisos.php';
requirePermiso('informes');
header('Content-Type: application/json');

$respondidas = $pdo->query('SELECT calificacion FROM encuestas_csat WHERE respondido_en IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN);
$total = count($respondidas);
$promedio = $total > 0 ? round(array_sum($respondidas) / $total, 2) : null;
$distribucion = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
foreach ($respondidas as $c) {
    if (isset($distribucion[(int)$c])) $distribucion[(int)$c]++;
}

$pendientes = (int)$pdo->query('SELECT COUNT(*) FROM encuestas_csat WHERE respondido_en IS NULL')->fetchColumn();

echo json_encode([
    'success' => true,
    'total_respondidas' => $total,
    'total_pendientes' => $pendientes,
    'promedio' => $promedio,
    'distribucion' => $distribucion,
], JSON_UNESCAPED_UNICODE);
