<?php
// Buscador global del menú lateral (Ctrl+K): incidencias, clientes y
// equipos del padrón que coincidan con el texto, en una sola llamada.
// Cada categoría se omite si el usuario en sesión no tiene permiso sobre
// ese módulo (auth/permisos.php), para no filtrar datos que no debería ver.
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../auth/permisos.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$q = trim((string)($_GET['q'] ?? ''));
if (mb_strlen($q) < 2) {
    echo json_encode(['incidencias' => [], 'clientes' => [], 'equipos' => []]);
    exit;
}

$like = '%' . $q . '%';
$resultado = ['incidencias' => [], 'clientes' => [], 'equipos' => []];

try {
    if (tienePermiso('incidencias')) {
        $stmt = $pdo->prepare(
            "SELECT id, numero_incidente, reporte_cliente, cliente, sucursal, categoria, falla, estatus, numero_serie
             FROM incidencias
             WHERE numero_incidente LIKE ? OR reporte_cliente LIKE ? OR cliente LIKE ?
                OR sucursal LIKE ? OR falla LIKE ? OR numero_serie LIKE ?
             ORDER BY id DESC LIMIT 6"
        );
        $stmt->execute([$like, $like, $like, $like, $like, $like]);
        $resultado['incidencias'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    if (tienePermiso('clientes')) {
        $stmt = $pdo->prepare(
            "SELECT id, nombre, rfc FROM clientes WHERE nombre LIKE ? OR rfc LIKE ? ORDER BY nombre LIMIT 6"
        );
        $stmt->execute([$like, $like]);
        $resultado['clientes'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare(
            "SELECT id, cliente, sucursal, equipo, marca, modelo, numero_serie
             FROM padron_equipos
             WHERE numero_serie LIKE ? OR marca LIKE ? OR modelo LIKE ? OR equipo LIKE ? OR cliente LIKE ?
             ORDER BY id DESC LIMIT 6"
        );
        $stmt->execute([$like, $like, $like, $like, $like]);
        $resultado['equipos'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    echo json_encode($resultado, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('buscar-global.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'No se pudo completar la búsqueda.']);
}
