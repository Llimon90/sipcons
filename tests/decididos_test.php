<?php
// Decisiones D-24, D-25 y D-26 (H-15, H-18, H-19) y concurrencias C-H02 y C-H07.
require_once __DIR__ . '/../backend/lib/incidencias_helpers.php';
require_once __DIR__ . '/../backend/lib/finanzas_helpers.php';
require_once __DIR__ . '/../backend/lib/cotizaciones_helpers.php';

[$empD, $adminD] = empresaTemporal('Decididos');
como($empD, $adminD, 'Administrador');
$pdo->exec("INSERT INTO clientes (nombre, rfc, direccion, telefono, contactos, email) VALUES ('Hotel Catalogo', '', '', '', '', '')");

function altaD(int $emp, int $u, array $c = []): array {
    return llamar($emp, $u, 'Administrador', 'POST', 'backend/server.php', ['post' => $c + [
        'reporte_cliente' => '', 'cliente' => 'Hotel Catalogo', 'contacto' => 'x', 'sucursal' => '', 'fecha' => date('Y-m-d'),
        'tecnico' => '', 'status' => 'Abierto', 'falla' => 'Servicio', 'notas' => '']]);
}
function filaD(int $id): array {
    global $pdo;
    return $pdo->query("SELECT * FROM incidencias WHERE id = $id")->fetch(PDO::FETCH_ASSOC) ?: [];
}

grupo('Cliente del catálogo y trabajo realizado en el alta (D-26)');

prueba('H-19 · D-26: el alta rechaza un cliente que no está en el catálogo', function () use ($empD, $adminD) {
    $r = altaD($empD, $adminD, ['cliente' => 'Cliente Inventado ' . bin2hex(random_bytes(2))]);
    verdadero(empty($r['json']['success']) && $r['http'] === 400, "se aceptó un cliente fuera del catálogo (HTTP {$r['http']})");
});

prueba('D-26: el cliente se reconoce sin distinguir mayúsculas ni espacios, y se guarda como en el catálogo', function () use ($empD, $adminD) {
    $r = altaD($empD, $adminD, ['cliente' => '  hotel catalogo ']);
    como($empD, $adminD, 'Administrador');
    igual(200, $r['http'], 'alta (' . mb_substr($r['texto'], 0, 100) . '):');
    igual('Hotel Catalogo', filaD((int)$r['json']['id'])['cliente'], 'cliente guardado:');
});

prueba('D-26: al editar, cambiar a un cliente fuera del catálogo se rechaza; conservar el de una orden antigua no', function () use ($empD, $adminD) {
    global $pdo;
    $pdo->prepare("INSERT INTO incidencias (numero_incidente, cliente, contacto, fecha, categoria, estatus, falla, tecnico) VALUES (?, 'Cliente Historico', 'x', CURDATE(), 'General', 'Abierto', 'x', '')")
        ->execute(['DC-' . bin2hex(random_bytes(3))]);
    $id = (int)$pdo->lastInsertId();
    $editar = function (array $c) use ($empD, $adminD, $id) {
        $f = filaD($id);
        return llamar($empD, $adminD, 'Administrador', 'POST', 'backend/actualiza.php', ['post' => $c + [
            'id' => $id, 'version' => versionIncidencia($f), 'reporte_cliente' => '', 'cliente' => $f['cliente'], 'contacto' => 'x', 'sucursal' => '',
            'categoria' => 'General', 'fecha' => substr($f['fecha'], 0, 10), 'tecnico' => '', 'estatus' => 'Abierto', 'falla' => 'x', 'accion' => '', 'notas' => 'n']]);
    };
    igual(200, $editar([])['http'], 'editar conservando el cliente histórico:');
    $r = $editar(['cliente' => 'Otro Inventado']);
    como($empD, $adminD, 'Administrador');
    verdadero($r['http'] === 400, "se cambió a un cliente fuera del catálogo (HTTP {$r['http']})");
});

prueba('H-19 · D-26: el alta guarda el trabajo realizado', function () use ($empD, $adminD) {
    $r = altaD($empD, $adminD, ['status' => 'Completado', 'accion' => 'Se cambió el capacitor']);
    como($empD, $adminD, 'Administrador');
    igual('Se cambió el capacitor', filaD((int)($r['json']['id'] ?? 0))['accion'] ?? null, 'trabajo realizado:');
});

grupo('Versión obligatoria al editar (D-25)');

prueba('H-18 · D-25: editar sin la versión que se leyó responde 400 y no guarda', function () use ($empD, $adminD) {
    global $pdo;
    $pdo->prepare("INSERT INTO incidencias (numero_incidente, cliente, contacto, fecha, categoria, estatus, falla, tecnico, notas) VALUES (?, 'Hotel Catalogo', 'x', CURDATE(), 'General', 'Abierto', 'x', '', 'original')")
        ->execute(['DV-' . bin2hex(random_bytes(3))]);
    $id = (int)$pdo->lastInsertId();
    $r = llamar($empD, $adminD, 'Administrador', 'POST', 'backend/actualiza.php', ['post' => [
        'id' => $id, 'reporte_cliente' => '', 'cliente' => 'Hotel Catalogo', 'contacto' => 'x', 'sucursal' => '', 'categoria' => 'General',
        'fecha' => date('Y-m-d'), 'tecnico' => '', 'estatus' => 'Abierto', 'falla' => 'x', 'accion' => '', 'notas' => 'pisada']]);
    como($empD, $adminD, 'Administrador');
    igual(400, $r['http'], 'respuesta sin versión:');
    igual('original', filaD($id)['notas'], 'notas:');
});

grupo('Tickets automáticos en el historial (D-24)');

prueba('H-15 · D-24: el ticket automático queda en el historial como creado por "Sistema"', function () use ($empD, $adminD) {
    global $pdo;
    $cliente = 'Cliente Padron ' . bin2hex(random_bytes(2));
    $pdo->prepare("INSERT INTO clientes (nombre, rfc, direccion, telefono, contactos, email) VALUES (?, '', '', '', '', '')")->execute([$cliente]);
    $pdo->prepare("INSERT INTO padron_equipos (cliente, sucursal, equipo, marca, modelo, numero_serie, servicio, frecuencia_servicio, proximo_servicio, origen)
                   VALUES (?, '', 'Minisplit', 'M', 'X', ?, 1, 3, ?, 'Externo')")
        ->execute([$cliente, 'P-' . bin2hex(random_bytes(3)), date('Y-m-d', strtotime('+3 days'))]);
    llamar($empD, $adminD, 'Administrador', 'GET', 'backend/server.php');
    como($empD, $adminD, 'Administrador');
    $st = $pdo->prepare("SELECT id FROM incidencias WHERE cliente = ? AND reporte_cliente = 'AUTO-SERV'");
    $st->execute([$cliente]);
    $ticket = (int)$st->fetchColumn();
    verdadero($ticket > 0, 'no se generó el ticket automático');
    $h = $pdo->prepare("SELECT accion, usuario_nombre, usuario_id FROM auditoria WHERE tabla = 'incidencias' AND registro_id = ?");
    $h->execute([(string)$ticket]);
    $fila = $h->fetch(PDO::FETCH_ASSOC) ?: [];
    igual('CREATE', $fila['accion'] ?? null, 'acción en el historial:');
    igual('Sistema', $fila['usuario_nombre'] ?? null, 'creado por:');
});

grupo('Concurrencia (C-H02, C-H07)');

prueba('C-H02: dos cuentas simultáneas de la misma cotización no rebasan lo aprobado', function () use ($empD, $adminD) {
    global $pdo;
    $pdo->prepare("INSERT INTO clientes (nombre, rfc, direccion, telefono, contactos, email) VALUES ('Cliente CxC Sim', '', '', '', '', '')")->execute();
    $cli = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO inventario_articulos (sku, nombre, categoria, controla_existencia, unidad, existencia, costo_unitario, precio_venta)
                   VALUES (?, 'Servicio', 'Mano de obra', 0, 'servicio', 0, 100, 1000)")->execute(['SV-' . bin2hex(random_bytes(3))]);
    $art = (int)$pdo->lastInsertId();
    $cot = cotGuardar($pdo, 0, ['cliente_id' => $cli, 'fecha' => date('Y-m-d'), 'vigencia_hasta' => date('Y-m-d', strtotime('+15 days')), 'moneda' => 'MXN'],
        [['articulo_id' => $art, 'cantidad' => 1]]);
    cotCambiarEstatus($pdo, (int)$cot['id'], 'enviada');
    cotCambiarEstatus($pdo, (int)$cot['id'], 'aprobada', ['aprobada_por' => 'Gerente']);
    llamarSimultaneo(4, $empD, $adminD, 'Administrador', 'POST', 'backend/finanzas.php', ['get' => ['accion' => 'crear_cuenta'], 'json' => [
        'cliente_id' => $cli, 'concepto' => 'Total', 'origen_tipo' => 'cotizacion', 'origen_id' => $cot['id'], 'subtotal' => 1000, 'iva' => 160]]);
    como($empD, $adminD, 'Administrador');
    $total = (float)$pdo->query("SELECT COALESCE(SUM(total), 0) FROM cuentas_cobrar WHERE origen_tipo = 'cotizacion' AND origen_id = " . (int)$cot['id'] . " AND estatus <> 'cancelada'")->fetchColumn();
    igual(1160.0, $total, 'total registrado contra la cotización:');
});

prueba('C-H07: ajustes simultáneos al mismo conteo físico dejan la existencia igual al conteo', function () use ($empD, $adminD) {
    global $pdo;
    $pdo->prepare("INSERT INTO inventario_articulos (sku, nombre, categoria, existencia, costo_unitario) VALUES (?, 'Filtro', 'Refacción', 10, 5)")
        ->execute(['AJ-' . bin2hex(random_bytes(3))]);
    $art = (int)$pdo->lastInsertId();
    llamarSimultaneo(4, $empD, $adminD, 'Administrador', 'POST', 'backend/inventario.php', ['get' => ['accion' => 'movimiento'], 'json' => [
        'articulo_id' => $art, 'tipo' => 'ajuste', 'cantidad' => 7, 'motivo' => 'Conteo']]);
    como($empD, $adminD, 'Administrador');
    igual(7.0, (float)$pdo->query("SELECT existencia FROM inventario_articulos WHERE id = $art")->fetchColumn(), 'existencia final:');
});
