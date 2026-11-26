<?php
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../auth/permisos.php';
requirePermiso('inventario');
header('Content-Type: application/json');

$filtro = trim((string)($_GET['q'] ?? ''));
$soloActivos = ($_GET['solo_activos'] ?? '1') !== '0';
$soloBajoMinimo = ($_GET['bajo_minimo'] ?? '0') === '1';
$categoria = trim((string)($_GET['categoria'] ?? ''));

$sql = 'SELECT * FROM inventario_productos WHERE (sku LIKE ? OR nombre LIKE ? OR descripcion LIKE ? OR marca LIKE ? OR modelo LIKE ?)';
$parametros = ["%$filtro%", "%$filtro%", "%$filtro%", "%$filtro%", "%$filtro%"];

if ($soloActivos) {
    $sql .= ' AND activo = 1';
}
if ($soloBajoMinimo) {
    $sql .= ' AND stock_actual <= stock_minimo';
}
require_once __DIR__ . '/lib/inventario_helpers.php';
if ($categoria !== '' && in_array($categoria, INV_CATEGORIAS, true)) {
    $sql .= ' AND categoria = ?';
    $parametros[] = $categoria;
}
$sql .= ' ORDER BY categoria, nombre';

$stmt = $pdo->prepare($sql);
$stmt->execute($parametros);
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
