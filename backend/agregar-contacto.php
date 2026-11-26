<?php
// O-6: agrega un contacto al cliente de una orden de servicio sin salir de la orden.
// Lo puede hacer quien edita la orden (permiso incidencias y, para el Técnico, solo en sus
// órdenes: D-9). No repite contactos (sin distinguir mayúsculas). Mantiene sincronizada la
// columna clientes.contactos (nombres separados por "; ").
//   POST JSON { incidencia_id, nombre, telefono?, email? } -> { exito, contacto }
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../auth/permisos.php';
require_once __DIR__ . '/../auth/audit.php';
require_once __DIR__ . '/lib/incidencias_helpers.php';
header('Content-Type: application/json; charset=utf-8');

function responderContacto(array $datos, int $http = 200): void {
    http_response_code($http);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') responderContacto(['exito' => false, 'mensaje' => 'Método no permitido.'], 405);
if (!tienePermiso('incidencias')) responderContacto(['exito' => false, 'mensaje' => 'Tu rol no puede editar órdenes de servicio.'], 403);

$body = json_decode(file_get_contents('php://input'), true) ?: [];
$nombre = preg_replace('/\s+/u', ' ', trim((string)($body['nombre'] ?? '')));
$telefono = mb_substr(trim((string)($body['telefono'] ?? '')), 0, 50) ?: null;
$email = mb_substr(trim((string)($body['email'] ?? '')), 0, 150) ?: null;
if ($nombre === '' || mb_strlen($nombre) > 150) responderContacto(['exito' => false, 'mensaje' => 'Escribe el nombre del contacto.'], 400);
if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) responderContacto(['exito' => false, 'mensaje' => 'El correo del contacto no es válido.'], 400);

try {
    $orden = ordenVisiblePorId($pdo, (int)($body['incidencia_id'] ?? 0));  // D-9
    if (!$orden) responderContacto(['exito' => false, 'mensaje' => 'La orden de servicio no existe.'], 404);
    $cliente = clienteDelCatalogo($pdo, (string)$orden['cliente']);
    $st = $pdo->prepare('SELECT id, nombre FROM clientes WHERE nombre = ? LIMIT 1');
    $st->execute([(string)$cliente]);
    $cli = $st->fetch(PDO::FETCH_ASSOC);
    if (!$cli) responderContacto(['exito' => false, 'mensaje' => 'El cliente de la orden no está en el catálogo.'], 400);

    $pdo->beginTransaction();
    $pdo->prepare('SELECT id FROM clientes WHERE id = ? FOR UPDATE')->execute([$cli['id']]);
    $st = $pdo->prepare('SELECT nombre FROM cliente_contactos WHERE cliente_id = ? AND LOWER(TRIM(nombre)) = LOWER(?)');
    $st->execute([$cli['id'], $nombre]);
    $existente = $st->fetchColumn();
    if ($existente === false) {
        $pdo->prepare('INSERT INTO cliente_contactos (cliente_id, nombre, telefono, email) VALUES (?, ?, ?, ?)')
            ->execute([$cli['id'], $nombre, $telefono, $email]);
        $st = $pdo->prepare('SELECT nombre FROM cliente_contactos WHERE cliente_id = ? ORDER BY id');
        $st->execute([$cli['id']]);
        $pdo->prepare('UPDATE clientes SET contactos = ? WHERE id = ?')->execute([implode('; ', $st->fetchAll(PDO::FETCH_COLUMN)), $cli['id']]);
        registrarAuditoria('clientes', $cli['id'], 'UPDATE', null, ['contacto_agregado' => $nombre, 'desde_orden' => $orden['numero_incidente']], $cli['nombre']);
    }
    $pdo->commit();
    responderContacto(['exito' => true, 'contacto' => ['nombre' => $existente !== false ? $existente : $nombre, 'telefono' => $telefono, 'email' => $email],
        'ya_existia' => $existente !== false]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Agregar contacto: ' . $e->getMessage());
    responderContacto(['exito' => false, 'mensaje' => 'No se pudo agregar el contacto. Intenta de nuevo.'], 500);
}
