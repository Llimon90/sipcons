<?php
// Elimina un cliente sin historial (solo Administrador). L-5 · H-04 (auditoría integral 2026-11-24):
// con el catálogo bloqueado (cliCandado) y en una sola transacción sobre $pdo: el cliente no se borra si
// otro usuario le registra una orden, venta, cotización o cuenta mientras tanto.
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../auth/permisos.php';
requirePermisoAlguno('clientes');
require_once __DIR__ . '/../auth/audit.php';
require_once __DIR__ . '/../config/contactos_cliente.php';
require_once __DIR__ . '/lib/clientes_helpers.php';
header('Content-Type: application/json');

function responderBaja(int $http, array $d): void {
    http_response_code($http);
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

// Solo el Administrador elimina clientes (igual que órdenes de servicio y ventas)
if (!esAdministracion()) responderBaja(403, ['error' => 'Solo un administrador puede eliminar clientes.']);
if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') responderBaja(405, ['error' => 'Método no permitido']);
$id = (int)($_GET['id'] ?? 0);
if (!$id) responderBaja(400, ['error' => 'ID de cliente no proporcionado']);

$candado = null;
try {
    $candado = cliCandado($pdo);
    $pdo->beginTransaction();
    $st = $pdo->prepare('SELECT nombre, rfc, direccion, telefono, contactos, email FROM clientes WHERE id = ? FOR UPDATE');
    $st->execute([$id]);
    $anterior = $st->fetch(PDO::FETCH_ASSOC);
    if (!$anterior) {
        $pdo->rollBack();
        responderBaja(404, ['error' => 'Cliente no encontrado']);
    }
    // Un cliente con historial no se borra: sus órdenes, ventas, equipos, cotizaciones
    // y cuentas por cobrar (L-5) lo nombran y quedarían huérfanos.
    if ($error = cliErrorEliminar($pdo, $id, (string)$anterior['nombre'])) {
        $pdo->rollBack();
        responderBaja(409, ['error' => $error]);
    }
    $anterior['contactos_detalle'] = obtenerContactosCliente($id);
    try { $pdo->prepare('DELETE FROM cliente_contactos WHERE cliente_id = ?')->execute([$id]); } catch (PDOException $e) { /* sin contactos */ }
    // L-5: sus sucursales se van con él (no quedan huérfanas)
    try { $pdo->prepare('DELETE FROM cliente_sucursales WHERE cliente_id = ?')->execute([$id]); } catch (PDOException $e) { /* sin multisucursal */ }
    $pdo->prepare('DELETE FROM clientes WHERE id = ?')->execute([$id]);
    $pdo->commit();
    registrarAuditoria('clientes', $id, 'DELETE', $anterior, null);
    responderBaja(200, ['success' => true]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Eliminar cliente: ' . $e->getMessage());
    responderBaja(500, ['error' => 'No se pudo eliminar el cliente. Inténtalo de nuevo.']);
} finally {
    if ($candado) cliSoltarCandado($pdo, $candado);
}
