<?php
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../auth/permisos.php';
requirePermisoAlguno('clientes');
require_once __DIR__ . '/../auth/audit.php';
require_once __DIR__ . '/../config/contactos_cliente.php';
require_once __DIR__ . '/lib/clientes_helpers.php';
header('Content-Type: application/json');

function responderCliente(array $d, int $http = 200): void {
    http_response_code($http);
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

$id = (int)($_POST['id'] ?? 0);
$nombre = cliNormalizarNombre((string)($_POST['nombre'] ?? ''));
$rfc = trim((string)($_POST['rfc'] ?? ''));
$direccion = trim((string)($_POST['direccion'] ?? ''));
$telefono = trim((string)($_POST['telefono'] ?? ''));
$email = trim((string)($_POST['email'] ?? ''));

// Contactos: lista JSON [{nombre, telefono, email}] (o texto "Juan; Pedro").
$listaContactos = isset($_POST['contactos_json'])
    ? normalizarContactos($_POST['contactos_json'])
    : normalizarContactos(array_map(fn($n) => ['nombre' => $n], array_filter(array_map('trim', explode(';', $_POST['contactos'] ?? '')))));
$contactos = contactosComoTexto($listaContactos);

$candado = null;
try {
    // D-53 · L-4: nombre y correo únicos en la empresa; el renombre arrastra órdenes, padrón y ventas
    $candado = cliCandado($pdo);
    $pdo->beginTransaction();
    $st = $pdo->prepare('SELECT nombre, rfc, direccion, telefono, contactos, email FROM clientes WHERE id = ? FOR UPDATE');
    $st->execute([$id]);
    $anterior = $st->fetch(PDO::FETCH_ASSOC);
    if (!$anterior) { $pdo->rollBack(); responderCliente(['success' => false, 'error' => 'El cliente no existe.'], 404); }
    // L2-3 · H-03 (fase 4): con un duplicado heredado (otro cliente con el mismo nombre) no se sabe de cuál
    // son las órdenes, el padrón y las ventas: se renombra solo este cliente del catálogo y esos registros
    // se quedan con el otro (no se arrastra historial ajeno). Así se puede desambiguar sin quedar atorado.
    $duplicado = cliDuplicadoDeNombre($pdo, (string)$anterior['nombre'], $id);
    if ($error = cliErrorDatos($pdo, $nombre, $email, $id, $anterior)) { $pdo->rollBack(); responderCliente(['success' => false, 'error' => $error, 'message' => $error], 409); }
    $anterior['contactos_detalle'] = obtenerContactosCliente($id);

    $pdo->prepare('UPDATE clientes SET nombre = ?, rfc = ?, direccion = ?, telefono = ?, contactos = ?, email = ? WHERE id = ?')
        ->execute([$nombre, $rfc, $direccion, $telefono, $contactos, $email, $id]);
    $arrastrados = $duplicado === null ? cliRenombrar($pdo, (string)$anterior['nombre'], $nombre) : [];
    $cambiaNombre = mb_strtolower(cliNormalizarNombre((string)$anterior['nombre'])) !== mb_strtolower($nombre);  // H-04 (fase 4 c)
    if ($duplicado !== null && $cambiaNombre) {
        // H-03 (fase 4 b): lo que se queda con el otro cliente no debe apuntar a las sucursales de este
        foreach (['incidencias', 'padron_equipos', 'ventas'] as $tabla) {
            try {
                $pdo->prepare("UPDATE $tabla SET sucursal_id = NULL WHERE UPPER(TRIM(cliente)) = UPPER(TRIM(?))
                               AND sucursal_id IN (SELECT id FROM cliente_sucursales WHERE cliente_id = ?)")->execute([(string)$anterior['nombre'], $id]);
            } catch (PDOException $e) { /* sin multisucursal */ }
        }
    }
    $pdo->commit();

    sincronizarContactosCliente($id, $listaContactos);
    registrarAuditoria('clientes', $id, 'UPDATE', $anterior, [
        'nombre' => $nombre, 'rfc' => $rfc, 'direccion' => $direccion, 'telefono' => $telefono,
        'contactos' => $contactos, 'contactos_detalle' => $listaContactos, 'email' => $email,
    ] + ($arrastrados ? ['renombre_actualizo' => $arrastrados] : []));
    responderCliente(['success' => true, 'actualizados' => $arrastrados] + ($duplicado !== null && $cambiaNombre
        ? ['aviso' => "Había otro cliente con el mismo nombre ({$duplicado}): sus órdenes, equipos y ventas se quedaron con él. Revisa cuáles son de este cliente."] : []));
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Actualizar cliente: ' . $e->getMessage());
    responderCliente(['success' => false, 'error' => 'No se pudo actualizar el cliente. Inténtalo de nuevo.'], 500);
} finally {
    if ($candado) cliSoltarCandado($pdo, $candado);
}
