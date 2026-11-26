<?php
// Auditoría integral 2026-11-24, bloque 3: integridad de clientes, padrón y ventas.
// L-4 renombre, L-5 baja con cuentas, D-53 unicidad, L-33 largo, L-6/L-7/L-29 padrón y ventas, L-32 sucursal.
require_once __DIR__ . '/../backend/lib/finanzas_helpers.php';
require_once __DIR__ . '/../backend/lib/clientes_helpers.php';
require_once __DIR__ . '/../backend/lib/importar_helpers.php';
require_once __DIR__ . '/../backend/lib/sucursales_helpers.php';

[$empC, $adminC] = empresaTemporal('Clientes integridad');
como($empC, $adminC, 'Administrador');

function clienteC(string $nombre, string $email = ''): int {
    global $pdo;
    $pdo->prepare("INSERT INTO clientes (nombre, rfc, direccion, telefono, contactos, email) VALUES (?, '', '', '', 'Contacto', ?)")->execute([$nombre, $email]);
    return (int)$pdo->lastInsertId();
}
function editarC(int $emp, int $u, int $id, string $nombre, string $email = ''): array {
    $r = llamar($emp, $u, 'Administrador', 'POST', 'backend/actualiza-cliente.php', ['post' => [
        'id' => $id, 'nombre' => $nombre, 'rfc' => '', 'direccion' => '', 'telefono' => '', 'email' => $email, 'contactos' => 'Contacto']]);
    como($emp, $u, 'Administrador');
    return $r;
}
function altaC(int $emp, int $u, string $nombre, string $email = ''): array {
    $r = llamar($emp, $u, 'Administrador', 'POST', 'backend/guardar-cliente.php', ['post' => [
        'nombre' => $nombre, 'rfc' => '', 'direccion' => '', 'telefono' => '', 'email' => $email, 'contactos' => 'Contacto']]);
    como($emp, $u, 'Administrador');
    return $r;
}
function eliminarC(int $emp, int $u, int $id): array {
    $r = llamar($emp, $u, 'Administrador', 'DELETE', 'backend/eliminar-cliente.php', ['get' => ['id' => $id]]);
    como($emp, $u, 'Administrador');
    return $r;
}

grupo('Integridad de clientes, padrón y ventas (auditoría integral)');

prueba('L-4: renombrar un cliente actualiza sus órdenes, padrón y ventas, y ya no se puede borrar', function () use ($empC, $adminC) {
    global $pdo;
    $id = clienteC('Hotel Valle');
    $pdo->exec("INSERT INTO incidencias (numero_incidente, cliente, contacto, sucursal, fecha, categoria, estatus, falla, tecnico) VALUES ('CL-1', 'Hotel Valle', 'x', '', CURDATE(), 'General', 'Cerrado con factura', 'x', '')");
    $pdo->exec("INSERT INTO padron_equipos (cliente, sucursal, equipo, marca, modelo, numero_serie, origen, fecha_registro) VALUES ('Hotel Valle', '', 'Minisplit', 'M', 'X', 'SER-CL-1', 'Externo', CURDATE())");
    $pdo->exec("INSERT INTO ventas (folio, cliente, fecha_registro) VALUES ('V-CL-1', 'Hotel Valle', CURDATE())");
    $r = editarC($empC, $adminC, $id, 'Hotel Valle SA');
    verdadero(!empty($r['json']['success']), 'renombre: ' . $r['texto']);
    foreach (['incidencias', 'padron_equipos', 'ventas'] as $t) {
        igual(1, (int)$pdo->query("SELECT COUNT(*) FROM $t WHERE cliente = 'Hotel Valle SA'")->fetchColumn(), "$t con el nombre nuevo:");
    }
    igual(409, eliminarC($empC, $adminC, $id)['http'], 'borrar al cliente renombrado con historial:');
});

prueba('D-53 · L-4: el nombre no puede quedar vacío ni repetido al editar', function () use ($empC, $adminC) {
    $a = clienteC('Cliente Uno');
    clienteC('Cliente Dos');
    verdadero(empty(editarC($empC, $adminC, $a, '  cliente dos ')['json']['success']), 'acepta un nombre repetido');
    verdadero(empty(editarC($empC, $adminC, $a, '   ')['json']['success']), 'acepta un nombre vacío');
});

prueba('D-53: el correo de un cliente no se repite en la empresa (vacío sí)', function () use ($empC, $adminC) {
    clienteC('Con correo', 'compras@valle.test');
    verdadero(empty(altaC($empC, $adminC, 'Otro con correo', 'COMPRAS@valle.test')['json']['success']), 'alta con correo repetido');
    verdadero(!empty(altaC($empC, $adminC, 'Sin correo A')['json']['success']), 'alta sin correo A');
    verdadero(!empty(altaC($empC, $adminC, 'Sin correo B')['json']['success']), 'alta sin correo B');
    $b = clienteC('Cambia correo');
    verdadero(empty(editarC($empC, $adminC, $b, 'Cambia correo', 'compras@valle.test')['json']['success']), 'edición con correo repetido');
});

prueba('D-53: otra empresa sí puede repetir nombre y correo', function () use ($empC, $adminC) {
    clienteC('Compartido', 'mismo@x.test');
    [$emp2, $admin2] = empresaTemporal('Clientes otra');
    como($emp2, $admin2, 'Administrador');
    verdadero(!empty(altaC($emp2, $admin2, 'Compartido', 'mismo@x.test')['json']['success']), 'la otra empresa no puede');
    como($empC, $adminC, 'Administrador');
});

prueba('L-33: el nombre admite hasta 100 caracteres', function () use ($empC, $adminC) {
    verdadero(empty(altaC($empC, $adminC, str_repeat('A', 101))['json']['success']), 'acepta 101 caracteres');
    verdadero(!empty(altaC($empC, $adminC, str_repeat('B', 100))['json']['success']), 'rechaza 100 caracteres');
});

prueba('L-5: un cliente con cuentas por cobrar no se elimina; sin historial se va con sus sucursales', function () use ($empC, $adminC) {
    global $pdo;
    $id = clienteC('Debe dinero');
    finCrearCuenta($pdo, ['cliente_id' => $id, 'concepto' => 'Manual', 'subtotal' => 100, 'iva' => 16]);
    $r = eliminarC($empC, $adminC, $id);
    igual(409, $r['http'], 'eliminar con cuenta:');
    verdadero(str_contains((string)($r['json']['error'] ?? ''), 'cuentas por cobrar'), 'mensaje: ' . $r['texto']);
    $libre = clienteC('Sin historial');
    sucResolver($pdo, $libre, 'Matriz');
    igual(200, eliminarC($empC, $adminC, $libre)['http'], 'eliminar sin historial:');
    igual(0, (int)$pdo->query("SELECT COUNT(*) FROM cliente_sucursales WHERE cliente_id = $libre")->fetchColumn(), 'sucursales huérfanas:');
});

prueba('L-6: un equipo de una venta no se quita desde el padrón (la venta queda intacta)', function () use ($empC, $adminC) {
    global $pdo;
    clienteC('Compra equipos');
    $pdo->exec("INSERT INTO ventas (folio, cliente, fecha_registro) VALUES ('V-CL-6', 'Compra equipos', CURDATE())");
    $venta = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO venta_detalles (venta_id, equipo, marca, modelo, numero_serie) VALUES (?, 'Minisplit', 'M', 'X', 'SER-CL-6')")->execute([$venta]);
    $det = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO padron_equipos (cliente, sucursal, equipo, marca, modelo, numero_serie, origen, venta_id, venta_detalle_id, fecha_registro) VALUES ('Compra equipos', '', 'Minisplit', 'M', 'X', 'SER-CL-6', 'Venta propia', ?, ?, CURDATE())")->execute([$venta, $det]);
    $eq = (int)$pdo->lastInsertId();
    $r = llamar($empC, $adminC, 'Administrador', 'DELETE', 'backend/elimina_equipo_padron.php', ['get' => ['id' => $eq]]);
    como($empC, $adminC, 'Administrador');
    igual(409, $r['http'], 'quitar del padrón un equipo vendido:');
    igual(1, (int)$pdo->query("SELECT COUNT(*) FROM venta_detalles WHERE id = $det")->fetchColumn(), 'la venta perdió la línea:');
});

prueba('L-7 · L-29: eliminar una venta quita sus equipos del padrón y deja la serie en las órdenes', function () use ($empC, $adminC) {
    global $pdo;
    clienteC('Venta borrada');
    $pdo->exec("INSERT INTO ventas (folio, cliente, fecha_registro) VALUES ('V-CL-7', 'Venta borrada', CURDATE())");
    $venta = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO padron_equipos (cliente, sucursal, equipo, marca, modelo, numero_serie, origen, venta_id, fecha_registro) VALUES ('Venta borrada', '', 'Minisplit', 'M', 'X', 'SER-CL-7', 'Venta propia', ?, CURDATE())")->execute([$venta]);
    $eq = (int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO incidencias (numero_incidente, cliente, contacto, sucursal, fecha, categoria, estatus, falla, tecnico) VALUES ('CL-7', 'Venta borrada', 'x', '', CURDATE(), 'General', 'Abierto', 'x', '')");
    $orden = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO incidencia_equipos (incidencia_id, padron_id, numero_serie) VALUES (?, ?, 'SER-CL-7')")->execute([$orden, $eq]);
    $r = llamar($empC, $adminC, 'Administrador', 'POST', 'backend/eliminar_venta.php', ['json' => ['id' => $venta]]);
    como($empC, $adminC, 'Administrador');
    verdadero(!empty($r['json']['exito']), 'eliminar venta: ' . $r['texto']);
    igual(0, (int)$pdo->query("SELECT COUNT(*) FROM padron_equipos WHERE id = $eq")->fetchColumn(), 'equipo en el padrón:');
    igual(null, $pdo->query("SELECT padron_id FROM incidencia_equipos WHERE incidencia_id = $orden")->fetchColumn() ?: null, 'liga colgando:');
    igual('SER-CL-7', $pdo->query("SELECT numero_serie FROM incidencia_equipos WHERE incidencia_id = $orden")->fetchColumn(), 'la orden conserva la serie:');
});

prueba('L-32: usar una sucursal desactivada la vuelve a activar', function () {
    global $pdo;
    $cli = clienteC('Sucursal cerrada');
    [$suc] = sucResolver($pdo, $cli, 'Norte');
    $pdo->prepare('UPDATE cliente_sucursales SET activo = 0 WHERE id = ?')->execute([$suc]);
    [$otra] = sucResolver($pdo, $cli, 'Norte');
    igual($suc, $otra, 'misma sucursal:');
    igual(1, (int)$pdo->query("SELECT activo FROM cliente_sucursales WHERE id = $suc")->fetchColumn(), 'activa:');
});

prueba('D-53: la importación rechaza correos de cliente repetidos (en la base y en el archivo)', function () {
    global $pdo;
    clienteC('Ya importado', 'ya@importado.test');
    $ctx = impContexto($pdo, 'clientes');
    $vistos = [];
    $fila = fn($r, $n, $e) => ['renglon' => $r, 'datos' => ['nombre' => $n, 'rfc' => '', 'email' => $e, 'contacto' => '', 'contacto_email' => '', 'dias_credito' => '0'], 'errores' => [], 'avisos' => [], 'accion' => 'crear'];
    verdadero((bool)impValidar_clientes($fila(2, 'Nuevo A', 'ya@importado.test'), $ctx, $vistos)['errores'], 'correo de la base');
    verdadero(!impValidar_clientes($fila(3, 'Nuevo B', 'b@x.test'), $ctx, $vistos)['errores'], 'correo libre');
    verdadero((bool)impValidar_clientes($fila(4, 'Nuevo C', 'B@x.test'), $ctx, $vistos)['errores'], 'correo repetido en el archivo');
});

prueba('D-52: el Supervisor ve los días y el límite de crédito del cliente', function () use ($empC) {
    global $pdo;
    $id = clienteC('Con limite');
    $pdo->prepare('UPDATE clientes SET dias_credito = 30, limite_credito = 50000 WHERE id = ?')->execute([$id]);
    $sup = usuarioTemporal($empC, 'Supervisor');
    $r = llamar($empC, $sup, 'Supervisor', 'GET', 'backend/detalle-cliente.php', ['get' => ['id' => $id]]);
    igual(30, (int)($r['json']['dias_credito'] ?? 0), 'días:');
    igual(50000.0, (float)($r['json']['limite_credito'] ?? 0), 'límite:');
});
