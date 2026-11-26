<?php
// Sugerencias para capturar un equipo del padrón (D-41): tipos de equipo, marcas y modelos
// conocidos (padrón, catálogo de marcas y modelos de Soporte técnico y equipos del inventario).
// "modelos" trae, por modelo, el tipo y la marca con que ya se registró: al escribir un modelo
// conocido, la interfaz llena el tipo y la marca.
//   GET → { equipos: [...], marcas: [...], modelos: [{ modelo, marca, equipo }] }
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../auth/permisos.php';
requirePermisoAlguno('incidencias', 'clientes', 'ventas');
header('Content-Type: application/json');

$equipos = $marcas = $modelos = [];
$agregar = function (?string $modelo, ?string $marca, ?string $equipo) use (&$equipos, &$marcas, &$modelos) {
    $modelo = trim((string)$modelo); $marca = trim((string)$marca); $equipo = trim((string)$equipo);
    if ($equipo !== '') $equipos[mb_strtoupper($equipo)] ??= $equipo;
    if ($marca !== '') $marcas[mb_strtoupper($marca)] ??= $marca;
    if ($modelo !== '') $modelos[mb_strtoupper($modelo)] ??= ['modelo' => $modelo, 'marca' => $marca, 'equipo' => $equipo];
};
try {
    foreach ($pdo->query("SELECT modelo, marca, equipo FROM padron_equipos GROUP BY modelo, marca, equipo ORDER BY MAX(id) DESC LIMIT 2000") as $f) $agregar($f['modelo'], $f['marca'], $f['equipo']);
} catch (Throwable $e) { /* sin padrón */ }
try {
    foreach ($pdo->query("SELECT m.nombre AS modelo, ma.nombre AS marca, m.tipo_equipo AS equipo FROM modelos m JOIN marcas ma ON ma.id = m.marca_id") as $f) $agregar($f['modelo'], $f['marca'], $f['equipo']);
} catch (Throwable $e) { /* sin catálogo de marcas */ }
try {
    foreach ($pdo->query("SELECT modelo, marca, nombre AS equipo FROM inventario_articulos WHERE categoria = 'Equipo' AND activo = 1") as $f) $agregar($f['modelo'], $f['marca'], null);
} catch (Throwable $e) { /* sin inventario */ }

$ordenar = function (array $a) { natcasesort($a); return array_values($a); };
echo json_encode(['exito' => true, 'equipos' => $ordenar($equipos), 'marcas' => $ordenar($marcas), 'modelos' => array_values($modelos)], JSON_UNESCAPED_UNICODE);
