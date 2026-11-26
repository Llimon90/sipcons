<?php
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../auth/permisos.php';
requirePermiso('inventario');
header('Content-Type: application/json');

$filtro = trim((string)($_GET['q'] ?? ''));
$soloActivos = ($_GET['solo_activos'] ?? '1') !== '0';
$soloBajoMinimo = ($_GET['bajo_minimo'] ?? '0') === '1';

$sql = 'SELECT * FROM inventario_productos WHERE (sku LIKE ? OR nombre LIKE ? OR descripcion LIKE ?)';
$parametros = ["%$filtro%", "%$filtro%", "%$filtro%"];

if ($soloActivos) {
    $sql .= ' AND activo = 1';
}
if ($soloBajoMinimo) {
    $sql .= ' AND stock_actual <= stock_minimo';
}
$sql .= ' ORDER BY nombre';

$stmt = $pdo->prepare($sql);
$stmt->execute($parametros);
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
