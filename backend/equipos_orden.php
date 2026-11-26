<?php
// Equipos de una orden de servicio (D-41).
//   GET  ?accion=listar&incidencia_id=
//   POST ?accion=agregar  JSON: incidencia_id, numero_serie [, equipo, marca, modelo]
//        (409 motivo "equipo_nuevo": la serie no está en el padrón; se piden sus datos)
//   POST ?accion=quitar   JSON: incidencia_id, id   (no borra el equipo del padrón)
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../auth/permisos.php';
require_once __DIR__ . '/../auth/audit.php';
require_once __DIR__ . '/lib/equipos_orden_helpers.php';
requirePermiso('incidencias');

header('Content-Type: application/json');

function responderEquipos(int $codigo, array $datos): void {
    http_response_code($codigo);
    echo json_encode(['exito' => $codigo < 400] + $datos, JSON_UNESCAPED_UNICODE);
    exit;
}

$accion = $_GET['accion'] ?? 'listar';
$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$body = $metodo === 'POST' ? (json_decode(file_get_contents('php://input'), true) ?: []) : [];

try {
    switch ("$metodo:$accion") {
        case 'GET:listar':
            $id = (int)($_GET['incidencia_id'] ?? 0);
            if (!ordenVisiblePorId($pdo, $id)) responderEquipos(404, ['mensaje' => 'La orden de servicio no existe.']);  // D-9
            responderEquipos(200, ['equipos' => eqoListar($pdo, $id)]);

        case 'POST:agregar':
            $r = eqoAgregar($pdo, (int)($body['incidencia_id'] ?? 0), (string)($body['numero_serie'] ?? ''), $body);
            responderEquipos(200, ['mensaje' => $r['alta_en_padron'] ? 'Equipo dado de alta en el padrón y agregado a la orden.' : 'Equipo agregado a la orden.']
                + $r + ['equipos' => eqoListar($pdo, (int)$body['incidencia_id'])]);

        case 'POST:quitar':
            eqoQuitar($pdo, (int)($body['incidencia_id'] ?? 0), (int)($body['id'] ?? 0));
            responderEquipos(200, ['mensaje' => 'Equipo quitado de la orden (sigue en el padrón).', 'equipos' => eqoListar($pdo, (int)$body['incidencia_id'])]);

        default:
            responderEquipos(404, ['mensaje' => 'Acción no reconocida.']);
    }
} catch (EquipoOrdenException $e) {
    responderEquipos($e->http, ['mensaje' => $e->getMessage(), 'motivo' => $e->motivo]);
} catch (Throwable $e) {
    error_log('equipos_orden.php: ' . $e->getMessage());
    responderEquipos(500, ['mensaje' => 'No se pudo completar la operación.']);
}
