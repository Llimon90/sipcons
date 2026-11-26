<?php
// Sucursales de un cliente (D-44).
//   GET  ?accion=listar&cliente_id= (o &cliente=nombre)  sucursales activas con sus equipos y órdenes
//        (&todas=1 incluye las desactivadas)
//   POST ?accion=guardar  JSON: id?, cliente_id, nombre, direccion, contacto, telefono, activo
//        (solo con el permiso de Clientes; el Técnico consulta, no edita)
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../auth/permisos.php';
require_once __DIR__ . '/../auth/audit.php';
require_once __DIR__ . '/../config/demo.php';
require_once __DIR__ . '/lib/sucursales_helpers.php';
requirePermisoAlguno('clientes', 'incidencias', 'ventas', 'cotizaciones');

header('Content-Type: application/json');

function responderSucursal(int $codigo, array $datos): void {
    http_response_code($codigo);
    echo json_encode(['exito' => $codigo < 400] + $datos, JSON_UNESCAPED_UNICODE);
    exit;
}

$accion = $_GET['accion'] ?? 'listar';
$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    switch ("$metodo:$accion") {
        case 'GET:listar':
            $clienteId = (int)($_GET['cliente_id'] ?? 0) ?: (int)sucClienteIdPorNombre($pdo, (string)($_GET['cliente'] ?? ''));
            responderSucursal(200, ['cliente_id' => $clienteId ?: null, 'sucursales' => $clienteId ? sucListar($pdo, $clienteId, ($_GET['todas'] ?? '') !== '1') : []]);

        case 'POST:guardar':
            if (!tienePermiso('clientes')) responderSucursal(403, ['mensaje' => 'Las sucursales se editan en Clientes; tu rol solo las consulta.']);
            demoBloquear('editar sucursales');
            [$antes, $despues] = sucGuardar($pdo, json_decode(file_get_contents('php://input'), true) ?: []);
            registrarAuditoria('clientes', (int)$despues['cliente_id'], $antes ? 'UPDATE' : 'CREATE',
                $antes ? ['sucursal' => $antes] : null, ['sucursal' => $despues], $despues['nombre']);
            responderSucursal(200, ['mensaje' => $antes ? 'Sucursal actualizada.' : 'Sucursal agregada.', 'sucursal' => $despues]);

        default:
            responderSucursal(404, ['mensaje' => 'Acción no reconocida.']);
    }
} catch (SucursalException $e) {
    responderSucursal(400, ['mensaje' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('sucursales.php: ' . $e->getMessage());
    responderSucursal(500, ['mensaje' => 'No se pudo completar la operación.']);
}
