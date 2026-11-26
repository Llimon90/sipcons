<?php
// Hallazgos BAJA de la ronda piloto: H-15 (candado del generador), S-H07 y N-H11 (fecha y
// hora de la empresa en el navegador), N-H08 (bandeja de Inicio), A-H10 (filtro de
// programadas) y C-H09 (marca de refacciones instaladas fuera de la transacción).
require_once __DIR__ . '/../backend/lib/incidencias_helpers.php';
require_once __DIR__ . '/../backend/lib/inventario_helpers.php';
require_once __DIR__ . '/../backend/lib/cotizaciones_helpers.php';
require_once __DIR__ . '/../backend/lib/periodicidad_helpers.php';

[$empB, $adminB] = empresaTemporal('Bajas');
como($empB, $adminB, 'Administrador');

function equipoProgramadoB(string $cliente): void {
    global $pdo;
    clienteCatalogo($cliente);
    $pdo->prepare("INSERT INTO padron_equipos (cliente, sucursal, equipo, marca, modelo, numero_serie, servicio, frecuencia_servicio, proximo_servicio, origen)
                   VALUES (?, '', 'Minisplit', 'M', 'X', ?, 1, 3, ?, 'Externo')")
        ->execute([$cliente, 'B-' . bin2hex(random_bytes(3)), date('Y-m-d', strtotime('+3 days'))]);
}

grupo('Generador de tickets (H-15, CV-19)');

prueba('H-15 · CV-19: si no se puede abrir el candado, el generador no corre (falla cerrado)', function () use ($empB, $adminB) {
    global $pdo;
    $cliente = 'Cliente Candado ' . bin2hex(random_bytes(2));
    equipoProgramadoB($cliente);
    $_SESSION['empresa_id'] = $empB;
    $ruta = rutaCandadoGenerador();
    @unlink($ruta);
    @mkdir($ruta);  // un directorio donde va el archivo: fopen falla
    try {
        llamar($empB, $adminB, 'Administrador', 'GET', 'backend/server.php');
        como($empB, $adminB, 'Administrador');
        $st = $pdo->prepare("SELECT COUNT(*) FROM incidencias WHERE cliente = ?");
        $st->execute([$cliente]);
        igual(0, (int)$st->fetchColumn(), 'tickets generados sin candado:');
    } finally {
        @rmdir($ruta);
        como($empB, $adminB, 'Administrador');
    }
});

grupo('Fecha y hora de la empresa (S-H07, N-H11)');

prueba('S-H07 · ZH-1: el navegador recibe la fecha de hoy en la zona de la empresa', function () use ($empB, $adminB) {
    global $pdo;
    $pdo->prepare("UPDATE empresas SET marca = JSON_SET(marca, '$.zona_horaria', 'Pacific/Kiritimati') WHERE id = ?")->execute([$empB]);
    try {
        $r = llamar($empB, $adminB, 'Administrador', 'GET', 'branding/marca.js.php');
        $hoy = (new DateTime('now', new DateTimeZone('Pacific/Kiritimati')))->format('Y-m-d');
        verdadero(str_contains($r['texto'], '"hoy":"' . $hoy . '"'), "marca.js.php no trae la fecha de la empresa ($hoy)");
        verdadero(str_contains($r['texto'], '"zona_horaria":"Pacific/Kiritimati"'), 'marca.js.php no trae la zona de la empresa');
    } finally {
        $pdo->prepare("UPDATE empresas SET marca = JSON_SET(marca, '$.zona_horaria', ?) WHERE id = ?")->execute([env('APP_TIMEZONE', 'America/Mexico_City'), $empB]);
        como($empB, $adminB, 'Administrador');
    }
});

prueba('N-H11: "Últimos cambios" trae los minutos transcurridos calculados en el servidor', function () use ($empB, $adminB) {
    global $pdo;
    $pdo->prepare("INSERT INTO auditoria (usuario_id, usuario_nombre, accion, tabla, registro_id, creado_en) VALUES (?, 'Admin', 'UPDATE', 'incidencias', '1', NOW() - INTERVAL 90 MINUTE)")
        ->execute([$adminB]);
    $r = llamar($empB, $adminB, 'Administrador', 'GET', 'backend/inicio.php', ['get' => ['accion' => 'panel']]);
    $c = $r['json']['cambios'][0] ?? [];
    verdadero(isset($c['hace_min']) && (int)$c['hace_min'] >= 89 && (int)$c['hace_min'] <= 91, 'minutos transcurridos: ' . json_encode($c['hace_min'] ?? null));
});

grupo('Bandeja de Inicio (N-H08)');

prueba('N-H08: con muchas urgentes, la bandeja trae también las completadas que cuenta el indicador', function () use ($empB, $adminB) {
    global $pdo;
    $ins = $pdo->prepare("INSERT INTO incidencias (numero_incidente, cliente, contacto, fecha, categoria, estatus, falla, tecnico) VALUES (?, 'C', 'x', ?, 'General', ?, 'x', '')");
    $pdo->beginTransaction();
    for ($i = 0; $i < 85; $i++) $ins->execute(['NB-' . bin2hex(random_bytes(4)), date('Y-m-d', strtotime('-30 days')), 'Abierto']);
    $ins->execute(['NB-C-' . bin2hex(random_bytes(3)), date('Y-m-d'), 'Completado']);
    $pdo->commit();
    $r = llamar($empB, $adminB, 'Administrador', 'GET', 'backend/inicio.php', ['get' => ['accion' => 'panel']]);
    $enBandeja = count(array_filter($r['json']['bandeja'] ?? [], fn($f) => $f['estatus'] === 'Completado'));
    igual((int)($r['json']['indicadores']['completadas'] ?? -1), $enBandeja, 'completadas en la bandeja contra el indicador:');
});

grupo('Búsqueda de programadas (A-H10)');

prueba('A-H10: "solo programadas" con un técnico no devuelve los mantenimientos sin asignar', function () use ($empB, $adminB) {
    $cliente = 'Cliente Programado ' . bin2hex(random_bytes(2));
    equipoProgramadoB($cliente);
    $sin = llamar($empB, $adminB, 'Administrador', 'GET', 'backend/buscar_reportes.php', ['get' => ['solo_programadas' => '1', 'cliente' => $cliente]]);
    verdadero(isset($sin['json'][0]), 'sin técnico debería listar el programado: ' . mb_substr($sin['texto'], 0, 100));
    $con = llamar($empB, $adminB, 'Administrador', 'GET', 'backend/buscar_reportes.php', ['get' => ['solo_programadas' => '1', 'cliente' => $cliente, 'tecnico' => 'Ana']]);
    verdadero(!isset($con['json'][0]), 'con técnico listó mantenimientos "Por asignar"');
});

grupo('Cotización posterior al servicio (C-H09)');

prueba('C-H09: si falla marcar las refacciones instaladas, la cotización no queda creada a medias', function () use ($empB, $adminB) {
    global $pdo;
    clienteCatalogo('Cliente Cotiza Bajas');
    $pdo->prepare("INSERT INTO inventario_articulos (sku, nombre, categoria, existencia, costo_unitario, precio_venta) VALUES (?, 'Sello', 'Refacción', 5, 10, 20)")
        ->execute(['CB' . bin2hex(random_bytes(4))]);
    $art = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO incidencias (numero_incidente, cliente, contacto, fecha, categoria, estatus, falla, tecnico) VALUES (?, 'Cliente Cotiza Bajas', 'x', CURDATE(), 'General', 'Completado', 'x', '')")
        ->execute(['CB-' . bin2hex(random_bytes(3))]);
    $orden = (int)$pdo->lastInsertId();
    $pdo->beginTransaction();
    invRegistrarMovimiento($pdo, $art, -1, 'salida', 'Consumo', 'incidencia', $orden, 'CB');
    $pdo->commit();
    $antes = (int)$pdo->query('SELECT COUNT(*) FROM cotizaciones')->fetchColumn();
    $tabla = 'e' . $empB . '_cotizacion_partidas';
    $pdo->exec("DROP TRIGGER IF EXISTS prueba_marca_$empB");
    $pdo->exec("CREATE TRIGGER prueba_marca_$empB BEFORE UPDATE ON `$tabla` FOR EACH ROW
                BEGIN IF NEW.cargada_incidencia = 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'falla de prueba'; END IF; END");
    try {
        try { cotCrearDesdeIncidencia($pdo, $orden, true); } catch (Throwable $e) { /* se espera la falla */ }
        if ($pdo->inTransaction()) $pdo->rollBack();
        igual($antes, (int)$pdo->query('SELECT COUNT(*) FROM cotizaciones')->fetchColumn(), 'cotizaciones después de la falla:');
    } finally {
        $pdo->exec("DROP TRIGGER IF EXISTS prueba_marca_$empB");
    }
});
