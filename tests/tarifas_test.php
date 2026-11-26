<?php
// Tarifas de servicio (D-43): la mano de obra se cobra por hora con la tarifa de la
// empresa; los servicios son precio cerrado con los conceptos que incluyen.
require_once __DIR__ . '/../backend/lib/inventario_helpers.php';
require_once __DIR__ . '/../backend/lib/cotizaciones_helpers.php';
require_once __DIR__ . '/../backend/lib/tarifas_helpers.php';

[$empT, $adminT] = empresaTemporal('Tarifas');
como($empT, $adminT, 'Administrador');
clienteCatalogo('Cliente Tarifas');

function miEmpresaT(int $emp, int $u, string $accion, ?array $json = null, string $rol = 'Administrador'): array {
    return llamar($emp, $u, $rol, $json === null ? 'GET' : 'POST', 'backend/mi_empresa.php', ['get' => ['accion' => $accion], 'json' => $json]);
}

grupo('Tarifas de servicio (D-43)');

prueba('D-43: la tarifa por hora crea y actualiza el concepto "Mano de obra (por hora)"', function () use ($empT, $adminT) {
    global $pdo;
    $r = miEmpresaT($empT, $adminT, 'guardar_tarifas', ['tarifa_hora' => 450, 'servicios' => []]);
    igual(200, $r['http'], 'guardar (' . mb_substr($r['texto'], 0, 150) . '):');
    miEmpresaT($empT, $adminT, 'guardar_tarifas', ['tarifa_hora' => 520.5, 'servicios' => []]);
    como($empT, $adminT, 'Administrador');
    $f = $pdo->query("SELECT * FROM inventario_articulos WHERE sku = '" . TAR_SKU_MANO_OBRA . "'")->fetchAll(PDO::FETCH_ASSOC);
    igual(1, count($f), 'conceptos de mano de obra por hora:');
    igual('Mano de obra', $f[0]['categoria'] ?? null, 'categoría:');
    igual('hr', $f[0]['unidad'] ?? null, 'unidad:');
    igual(520.5, (float)($f[0]['precio_venta'] ?? 0), 'precio por hora:');
    igual(0, (int)($f[0]['controla_existencia'] ?? 1), 'sin existencias:');
    $t = miEmpresaT($empT, $adminT, 'tarifas');
    igual(520.5, (float)($t['json']['tarifa_hora'] ?? 0), 'tarifa leída:');
});

prueba('D-43: los servicios guardan su precio cerrado y lo que incluyen; quitar uno lo desactiva', function () use ($empT, $adminT) {
    global $pdo;
    $r = miEmpresaT($empT, $adminT, 'guardar_tarifas', ['tarifa_hora' => 500, 'servicios' => [
        ['nombre' => 'Mantenimiento preventivo', 'precio' => 1800, 'incluye' => "Limpieza\nAjuste\nCalibración"],
        ['nombre' => 'Visita de diagnóstico', 'precio' => 600, 'incluye' => 'Revisión en sitio'],
    ]]);
    igual(200, $r['http'], 'guardar (' . mb_substr($r['texto'], 0, 150) . '):');
    $t = miEmpresaT($empT, $adminT, 'tarifas');
    $porNombre = array_column($t['json']['servicios'] ?? [], null, 'nombre');
    igual(1800.0, (float)($porNombre['Mantenimiento preventivo']['precio'] ?? 0), 'precio:');
    igual("Limpieza\nAjuste\nCalibración", $porNombre['Mantenimiento preventivo']['incluye'] ?? null, 'incluye:');
    $visita = (int)$porNombre['Visita de diagnóstico']['id'];
    miEmpresaT($empT, $adminT, 'guardar_tarifas', ['tarifa_hora' => 500, 'servicios' => [
        ['id' => (int)$porNombre['Mantenimiento preventivo']['id'], 'nombre' => 'Mantenimiento preventivo', 'precio' => 1900, 'incluye' => 'Limpieza'],
    ]]);
    como($empT, $adminT, 'Administrador');
    igual(0, (int)$pdo->query("SELECT activo FROM inventario_articulos WHERE id = $visita")->fetchColumn(), 'el servicio quitado sigue activo:');
    igual('Servicio', $pdo->query("SELECT categoria FROM inventario_articulos WHERE id = $visita")->fetchColumn(), 'categoría:');
});

prueba('D-43: solo el administrador de la cuenta cambia las tarifas', function () use ($empT) {
    $sup = usuarioTemporal($empT, 'Supervisor');
    $r = miEmpresaT($empT, $sup, 'guardar_tarifas', ['tarifa_hora' => 1, 'servicios' => []], 'Supervisor');
    igual(403, $r['http'], 'Supervisor:');
});

prueba('D-43: la mano de obra admite horas decimales en la orden', function () use ($empT, $adminT) {
    global $pdo;
    como($empT, $adminT, 'Administrador');
    $mo = (int)$pdo->query("SELECT id FROM inventario_articulos WHERE sku = '" . TAR_SKU_MANO_OBRA . "'")->fetchColumn();
    $pdo->prepare("INSERT INTO incidencias (numero_incidente, cliente, contacto, sucursal, fecha, categoria, estatus, falla, tecnico) VALUES (?, 'Cliente Tarifas', 'x', '', CURDATE(), 'General', 'Abierto', 'x', '')")
        ->execute(['TF-' . bin2hex(random_bytes(3))]);
    $orden = (int)$pdo->lastInsertId();
    $r = llamar($empT, $adminT, 'Administrador', 'POST', 'backend/inventario.php', ['get' => ['accion' => 'aplicar_servicio'], 'json' => ['incidencia_id' => $orden, 'articulo_id' => $mo, 'cantidad' => 1.5]]);
    igual(200, $r['http'], 'aplicar (' . mb_substr($r['texto'], 0, 120) . '):');
    igual(1.5, (float)$pdo->query("SELECT cantidad FROM incidencia_servicios WHERE incidencia_id = $orden")->fetchColumn(), 'horas:');
});

prueba('D-43: la cotización muestra lo que incluye cada servicio', function () use ($empT, $adminT) {
    global $pdo;
    como($empT, $adminT, 'Administrador');
    $serv = (int)$pdo->query("SELECT id FROM inventario_articulos WHERE nombre = 'Mantenimiento preventivo'")->fetchColumn();
    $cli = (int)$pdo->query("SELECT id FROM clientes WHERE nombre = 'Cliente Tarifas'")->fetchColumn();
    $cot = cotGuardar($pdo, 0, ['cliente_id' => $cli, 'fecha' => date('Y-m-d'), 'vigencia_hasta' => date('Y-m-d', strtotime('+15 days')), 'moneda' => 'MXN', 'tipo_cambio' => 1],
        [['articulo_id' => $serv, 'cantidad' => 1]]);
    igual('Limpieza', $cot['partidas'][0]['incluye'] ?? null, 'incluye en la partida:');
});
