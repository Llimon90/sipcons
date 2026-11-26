<?php
// D-9: el Técnico ve y edita solo las órdenes donde está asignado, en TODOS los
// endpoints (lista, detalle, edición, búsquedas, contadores, alertas, adjuntos,
// refacciones y cotizaciones). Hallazgos H-06, P-H04, N-H01, D-H02/D-H03 y H-16.
require_once __DIR__ . '/../backend/lib/incidencias_helpers.php';
require_once __DIR__ . '/../backend/lib/estadisticas_helpers.php';
require_once __DIR__ . '/../backend/lib/inventario_helpers.php';
require_once __DIR__ . '/../backend/lib/cotizaciones_helpers.php';

[$empT, $adminT] = empresaTemporal('Tecnico Ordenes');
$sx = bin2hex(random_bytes(2));
$nomA = "Tecnico Uno $sx";
$nomB = "Tecnico Dos $sx";
$tecA = usuarioTemporal($empT, 'Técnico', ['nombre' => $nomA]);
$tecB = usuarioTemporal($empT, 'Técnico', ['nombre' => $nomB]);
como($empT, $adminT, 'Administrador');

function ordenT(array $c = []): int {
    global $pdo;
    $f = $c + ['numero_incidente' => 'T9-' . bin2hex(random_bytes(3)), 'cliente' => 'CLIENTE T', 'contacto' => 'x', 'fecha' => date('Y-m-d', strtotime('-40 days')),
        'categoria' => 'General', 'estatus' => 'Asignado', 'falla' => 'x', 'tecnico' => '', 'notas' => 'original'];
    $cols = implode(', ', array_map(fn($k) => "`$k`", array_keys($f)));
    $pdo->prepare("INSERT INTO incidencias ($cols) VALUES (" . implode(',', array_fill(0, count($f), '?')) . ')')->execute(array_values($f));
    return (int)$pdo->lastInsertId();
}
function filaT(int $id): array {
    global $pdo;
    return $pdo->query("SELECT * FROM incidencias WHERE id = $id")->fetch(PDO::FETCH_ASSOC);
}
function editarT(int $emp, int $u, string $rol, int $id, array $cambios): array {
    $f = filaT($id);
    return llamar($emp, $u, $rol, 'POST', 'backend/actualiza.php', ['post' => $cambios + [
        'id' => $id, 'version' => versionIncidencia($f), 'reporte_cliente' => '', 'cliente' => $f['cliente'], 'contacto' => $f['contacto'],
        'sucursal' => '', 'categoria' => $f['categoria'], 'fecha' => substr($f['fecha'], 0, 10), 'tecnico' => $f['tecnico'],
        'estatus' => $f['estatus'], 'falla' => $f['falla'], 'accion' => '', 'notas' => $f['notas']]]);
}
function idsDe(array $filas): array { return array_map('intval', array_column($filas, 'id')); }
function noVisible(array $r, string $msg): void {
    verdadero(in_array($r['http'], [403, 404], true) || (isset($r['json']['exito']) && !$r['json']['exito']), "$msg (HTTP {$r['http']}: " . mb_substr($r['texto'], 0, 120) . ')');
}

$miaA = ordenT(['tecnico' => $nomA]);
$deB = ordenT(['tecnico' => $nomB]);
$compartida = ordenT(['tecnico' => "$nomB/$nomA"]);
$sinTecnico = ordenT(['estatus' => 'Abierto']);

grupo('Ver y editar (D-9)');

prueba('D-9: el Técnico abre el detalle de su orden y de una compartida', function () use ($empT, $tecA, $miaA, $compartida) {
    igual(200, llamar($empT, $tecA, 'Técnico', 'GET', 'backend/detalle.php', ['get' => ['id' => $miaA]])['http'], 'propia:');
    igual(200, llamar($empT, $tecA, 'Técnico', 'GET', 'backend/detalle.php', ['get' => ['id' => $compartida]])['http'], 'compartida:');
});

prueba('D-9: el Técnico no abre el detalle de una orden ajena ni de una sin técnico', function () use ($empT, $tecA, $deB, $sinTecnico) {
    noVisible(llamar($empT, $tecA, 'Técnico', 'GET', 'backend/detalle.php', ['get' => ['id' => $deB]]), 'abrió una ajena');
    noVisible(llamar($empT, $tecA, 'Técnico', 'GET', 'backend/detalle.php', ['get' => ['id' => $sinTecnico]]), 'abrió una sin técnico');
});

prueba('D-9: el Técnico edita su orden pero no una ajena', function () use ($empT, $tecA, $adminT, $miaA, $deB) {
    igual(200, editarT($empT, $tecA, 'Técnico', $miaA, ['notas' => 'cambio propio'])['http'], 'propia:');
    noVisible(editarT($empT, $tecA, 'Técnico', $deB, ['notas' => 'cambio ajeno']), 'editó una ajena');
    como($empT, $adminT, 'Administrador');
    igual('original', filaT($deB)['notas'], 'notas de la ajena:');
});

prueba('D-9: el Administrador sigue viendo y editando todas', function () use ($empT, $adminT, $deB) {
    igual(200, llamar($empT, $adminT, 'Administrador', 'GET', 'backend/detalle.php', ['get' => ['id' => $deB]])['http'], 'detalle:');
    $r = llamar($empT, $adminT, 'Administrador', 'GET', 'backend/server.php');
    verdadero(in_array($deB, idsDe(isset($r['json'][0]) ? $r['json'] : []), true), 'falta una orden en la lista del Administrador');
});

grupo('Listas, búsquedas, contadores y alertas (D-9)');

prueba('D-9: la lista principal del Técnico solo trae sus órdenes', function () use ($empT, $tecA, $miaA, $deB, $compartida, $sinTecnico) {
    $r = llamar($empT, $tecA, 'Técnico', 'GET', 'backend/server.php');
    $ids = idsDe(isset($r['json'][0]) ? $r['json'] : []);
    verdadero(in_array($miaA, $ids, true) && in_array($compartida, $ids, true), 'faltan sus órdenes');
    verdadero(!in_array($deB, $ids, true) && !in_array($sinTecnico, $ids, true), 'aparecen órdenes ajenas');
});

prueba('D-9: la búsqueda de órdenes del Técnico solo trae las suyas', function () use ($empT, $tecA, $miaA, $deB) {
    $r = llamar($empT, $tecA, 'Técnico', 'GET', 'backend/buscar_reportes.php', ['get' => ['cliente' => 'CLIENTE T']]);
    $filas = $r['json']['data'] ?? $r['json']['resultados'] ?? (isset($r['json'][0]) ? $r['json'] : []);
    $ids = idsDe($filas);
    verdadero(in_array($miaA, $ids, true), 'falta su orden: ' . mb_substr($r['texto'], 0, 120));
    verdadero(!in_array($deB, $ids, true), 'aparece una orden ajena');
});

prueba('D-9: el buscador global y el contador del menú solo cuentan las suyas', function () use ($empT, $tecA, $miaA, $deB) {
    $r = llamar($empT, $tecA, 'Técnico', 'GET', 'backend/inicio.php', ['get' => ['accion' => 'buscar', 'q' => 'CLIENTE T']]);
    $ids = idsDe($r['json']['incidencias'] ?? []);
    verdadero(in_array($miaA, $ids, true) && !in_array($deB, $ids, true), 'el buscador global muestra órdenes ajenas o no muestra la suya');
    $c = llamar($empT, $tecA, 'Técnico', 'GET', 'backend/inicio.php', ['get' => ['accion' => 'contadores']]);
    igual(2, (int)$c['json']['incidencias'], 'activas del Técnico (la suya y la compartida):');
});

prueba('D-9: las alertas de atraso del Técnico solo traen las suyas', function () use ($empT, $tecA, $miaA, $deB) {
    $r = llamar($empT, $tecA, 'Técnico', 'GET', 'backend/alertas_incidentes.php');
    $ids = idsDe($r['json']['alertas'] ?? []);
    verdadero(in_array($miaA, $ids, true), 'falta su orden atrasada');
    verdadero(!in_array($deB, $ids, true), 'aparece una ajena');
});

grupo('Adjuntos, refacciones y cotizaciones (D-9, D-12, RP-17)');

prueba('D-9: el Técnico no ve ni borra adjuntos de una orden ajena; los suyos sí', function () use ($empT, $tecA, $adminT, $miaA, $deB) {
    global $pdo;
    $dir = __DIR__ . '/../' . carpetaEmpresa('uploads');
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $ajeno = "inc{$deB}_" . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '_ajeno.jpg';
    $propio = "inc{$miaA}_" . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '_propio.jpg';
    foreach ([[$deB, $ajeno], [$miaA, $propio]] as [$o, $n]) {
        file_put_contents("$dir/$n", 'jpg');
        $pdo->prepare('INSERT INTO archivos_incidencias (incidencia_id, ruta_archivo) VALUES (?, ?)')->execute([$o, '../' . carpetaEmpresa('uploads') . "/$n"]);
    }
    try {
        igual(404, llamar($empT, $tecA, 'Técnico', 'GET', 'backend/archivo.php', ['get' => ['ruta' => carpetaEmpresa('uploads') . "/$ajeno"]])['http'], 'ver ajeno:');
        igual(200, llamar($empT, $tecA, 'Técnico', 'GET', 'backend/archivo.php', ['get' => ['ruta' => carpetaEmpresa('uploads') . "/$propio"]])['http'], 'ver propio:');
        noVisible(llamar($empT, $tecA, 'Técnico', 'POST', 'backend/eliminar_archivo.php', ['post' => ['id_incidencia' => $deB, 'url_archivo' => $ajeno]]), 'borró un adjunto ajeno');
        verdadero(is_file("$dir/$ajeno"), 'el adjunto ajeno se borró');
    } finally {
        @unlink("$dir/$ajeno"); @unlink("$dir/$propio");
        como($empT, $adminT, 'Administrador');
    }
});

prueba('D-9: el Técnico no registra ni consulta refacciones de una orden ajena', function () use ($empT, $tecA, $adminT, $miaA, $deB) {
    global $pdo;
    $pdo->prepare("INSERT INTO inventario_articulos (sku, nombre, categoria, existencia, costo_unitario) VALUES (?, 'Ref T', 'Refacción', 10, 1)")->execute(['T' . bin2hex(random_bytes(4))]);
    $art = (int)$pdo->lastInsertId();
    noVisible(llamar($empT, $tecA, 'Técnico', 'POST', 'backend/inventario.php', ['get' => ['accion' => 'consumir_incidencia'], 'json' => ['incidencia_id' => $deB, 'articulo_id' => $art, 'cantidad' => 1]]), 'consumió en una ajena');
    noVisible(llamar($empT, $tecA, 'Técnico', 'GET', 'backend/inventario.php', ['get' => ['accion' => 'consumos_incidencia', 'incidencia_id' => $deB]]), 'consultó consumos de una ajena');
    $r = llamar($empT, $tecA, 'Técnico', 'POST', 'backend/inventario.php', ['get' => ['accion' => 'consumir_incidencia'], 'json' => ['incidencia_id' => $miaA, 'articulo_id' => $art, 'cantidad' => 1]]);
    como($empT, $adminT, 'Administrador');
    verdadero(!empty($r['json']['exito']), 'no pudo registrar una refacción en su propia orden: ' . mb_substr($r['texto'], 0, 120));
});

prueba('D-9 · RP-17: cotizaciones: el Técnico no crea ni ve las de órdenes ajenas, ni las que no creó', function () use ($empT, $tecA, $adminT, $miaA, $deB) {
    global $pdo;
    $pdo->prepare('INSERT INTO clientes (nombre) VALUES (?)')->execute(['CLIENTE T']);
    noVisible(llamar($empT, $tecA, 'Técnico', 'POST', 'backend/cotizaciones.php', ['get' => ['accion' => 'crear_desde_incidencia'], 'json' => ['incidencia_id' => $deB]]), 'cotizó una orden ajena');
    noVisible(llamar($empT, $tecA, 'Técnico', 'GET', 'backend/cotizaciones.php', ['get' => ['accion' => 'listar', 'incidencia_id' => $deB]]), 'listó cotizaciones de una ajena');
    como($empT, $adminT, 'Administrador');
    $deAdmin = cotCrearDesdeIncidencia($pdo, $miaA);   // la crea el Administrador en una orden del Técnico
    noVisible(llamar($empT, $tecA, 'Técnico', 'GET', 'backend/cotizaciones.php', ['get' => ['accion' => 'detalle', 'id' => $deAdmin['id']]]), 'vio una cotización que no creó');
    $propia = llamar($empT, $tecA, 'Técnico', 'POST', 'backend/cotizaciones.php', ['get' => ['accion' => 'crear_desde_incidencia'], 'json' => ['incidencia_id' => $miaA]]);
    como($empT, $adminT, 'Administrador');
    verdadero(!empty($propia['json']['exito']), 'no pudo cotizar su propia orden: ' . mb_substr($propia['texto'], 0, 140));
});

grupo('Identidad del técnico (AS-7)');

prueba('H-16 · AS-7: un técnico con nombre o alias de 2 letras se reconoce', function () {
    igual(['JC'], separarTecnicos('JC'), 'separarTecnicos("JC"):');
    verdadero(tecnicoCoincide('JC', 'JC/Ana López'), 'tecnicoCoincide("JC", "JC/Ana López")');
});

grupo('Casos límite de la identidad (auditoría del paso 3)');

prueba('D-9: "Jose" no ve las órdenes de "José" (MySQL compara sin acentos)', function () use ($empT, $adminT) {
    $sx = bin2hex(random_bytes(2));
    $jose = usuarioTemporal($empT, 'Técnico', ['nombre' => "Jose Perez $sx"]);
    $deJose2 = ordenT(['tecnico' => "José Pérez $sx"]);
    $r = llamar($empT, $jose, 'Técnico', 'GET', 'backend/server.php');
    como($empT, $adminT, 'Administrador');
    verdadero(!in_array($deJose2, idsDe(isset($r['json'][0]) ? $r['json'] : []), true), 'Jose ve la orden de José');
});

prueba('D-9: si le reasignan la orden, el Técnico ya no edita su borrador de cotización', function () use ($empT, $tecA, $adminT, $nomA, $nomB) {
    global $pdo;
    $orden = ordenT(['tecnico' => $nomA]);
    $r = llamar($empT, $tecA, 'Técnico', 'POST', 'backend/cotizaciones.php', ['get' => ['accion' => 'crear_desde_incidencia'], 'json' => ['incidencia_id' => $orden]]);
    como($empT, $adminT, 'Administrador');
    $cot = $r['json']['cotizacion'] ?? null;
    verdadero($cot !== null, 'no se creó el borrador: ' . mb_substr($r['texto'], 0, 120));
    $pdo->prepare('UPDATE incidencias SET tecnico = ? WHERE id = ?')->execute([$nomB, $orden]);
    $fila = cotFila($pdo, (int)$cot['id']);
    $_SESSION = ['user_id' => $tecA, 'rol' => 'Técnico', 'nombre' => $nomA, 'empresa_id' => $empT];
    $puede = cotPuedeEditar($fila);
    como($empT, $adminT, 'Administrador');
    verdadero(!$puede, 'el Técnico sigue pudiendo editar el borrador de una orden que ya no es suya');
});
