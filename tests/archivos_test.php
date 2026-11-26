<?php
// Seguridad de archivos subidos: backend/archivo.php (ver/descargar) y
// backend/eliminar_archivo.php (borrar). Hallazgos H-21, H-14 y P-H02 de la ronda
// piloto y D-H01, D2-H02 a D2-H09 de las auditorías del parche (auditorias/2026-11-10-*).

[$empP, $adminP] = empresaTemporal('Archivos');
$tecP = usuarioTemporal($empP, 'Técnico', ['nombre' => 'Tecnico Archivos ' . bin2hex(random_bytes(2))]);
como($empP, $adminP, 'Administrador');

grupo('Archivos (MT-4, RP-5)');

prueba('P-H02 · RP-5: un Técnico sin permiso de finanzas no descarga comprobantes de cobro', function () use ($empP, $tecP, $adminP) {
    $rel = carpetaEmpresa('uploads') . '/finanzas';
    $dir = __DIR__ . '/../' . $rel;
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $nombre = 'cobro_prueba_' . bin2hex(random_bytes(4)) . '.pdf';
    file_put_contents("$dir/$nombre", '%PDF-1.4 comprobante de prueba');
    try {
        $r = llamar($empP, $tecP, 'Técnico', 'GET', 'backend/archivo.php', ['get' => ['ruta' => "$rel/$nombre"]]);
        verdadero(in_array($r['http'], [403, 404], true), "el Técnico descargó el comprobante (HTTP {$r['http']})");
    } finally {
        @unlink("$dir/$nombre");
        como($empP, $adminP, 'Administrador');
    }
});

prueba('D-H01 (auditoría del parche): "//" o "marca/.." no se saltan el permiso por módulo', function () use ($empP, $tecP, $adminP) {
    $rel = carpetaEmpresa('uploads') . '/finanzas';
    $dir = __DIR__ . '/../' . $rel;
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    if (!is_dir(__DIR__ . "/../uploads/e$empP/marca")) mkdir(__DIR__ . "/../uploads/e$empP/marca", 0755, true);
    $nombre = 'cobro_truco_' . bin2hex(random_bytes(4)) . '.pdf';
    file_put_contents("$dir/$nombre", '%PDF-1.4');
    try {
        foreach (["uploads/e$empP//finanzas/$nombre", "uploads/e$empP/marca/../finanzas/$nombre", "uploads/e$empP/./finanzas/$nombre"] as $ruta) {
            $r = llamar($empP, $tecP, 'Técnico', 'GET', 'backend/archivo.php', ['get' => ['ruta' => $ruta]]);
            verdadero($r['http'] === 404, "con \"$ruta\" el Técnico descargó el comprobante (HTTP {$r['http']})");
        }
    } finally {
        @unlink("$dir/$nombre");
        como($empP, $adminP, 'Administrador');
    }
});

prueba('Archivos: si el archivo físico ya no existe, su registro se puede quitar de la orden', function () use ($empP, $adminP) {
    global $pdo;
    $pdo->prepare("INSERT INTO incidencias (numero_incidente, cliente, contacto, fecha, categoria, estatus, falla) VALUES (?, 'C', 'x', CURDATE(), 'General', 'Abierto', 'x')")
        ->execute(['H-' . bin2hex(random_bytes(3))]);
    $orden = (int)$pdo->lastInsertId();
    $nombre = 'inc' . $orden . '_perdido_' . bin2hex(random_bytes(3)) . '.jpg';
    $pdo->prepare('INSERT INTO archivos_incidencias (incidencia_id, ruta_archivo) VALUES (?, ?)')->execute([$orden, '../' . carpetaEmpresa('uploads') . "/$nombre"]);
    $r = llamar($empP, $adminP, 'Administrador', 'POST', 'backend/eliminar_archivo.php', ['post' => ['id_incidencia' => $orden, 'url_archivo' => $nombre]]);
    como($empP, $adminP, 'Administrador');
    igual(200, $r['http'], 'respuesta (' . mb_substr($r['texto'], 0, 100) . '):');
    igual(0, (int)$pdo->query("SELECT COUNT(*) FROM archivos_incidencias WHERE incidencia_id = $orden")->fetchColumn(), 'registros que quedan:');
});

/** Venta con un adjunto registrado con la ruta indicada. Devuelve el id de venta_archivos. */
function adjuntoVenta(string $ruta): int {
    global $pdo;
    $pdo->prepare("INSERT INTO ventas (folio, cliente, fecha_registro) VALUES (?, 'CLIENTE PRUEBA', CURDATE())")->execute(['VT-T' . bin2hex(random_bytes(3))]);
    $venta = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO venta_archivos (venta_id, ruta_archivo, nombre_archivo) VALUES (?, ?, 'x.pdf')")->execute([$venta, $ruta]);
    return (int)$pdo->lastInsertId();
}

prueba('D2-H09 (H-21 reforzado): con permiso de ventas, una ruta guardada fuera de la empresa no se borra', function () use ($empP, $adminP) {
    global $pdo;
    $senuelo = realpath(__DIR__ . '/../storage/logs') . DIRECTORY_SEPARATOR . 'senuelo-' . bin2hex(random_bytes(4)) . '.txt';
    file_put_contents($senuelo, 'no debe borrarse');
    $va = adjuntoVenta('../storage/logs/' . basename($senuelo));
    $r = llamar($empP, $adminP, 'Administrador', 'POST', 'backend/eliminar_archivo.php', ['json' => ['modulo' => 'ventas', 'id' => $va]]);
    $sigue = is_file($senuelo);
    @unlink($senuelo);
    como($empP, $adminP, 'Administrador');
    verdadero($sigue, "se borró un archivo fuera de uploads/ (HTTP {$r['http']})");
    igual(1, (int)$pdo->query("SELECT COUNT(*) FROM venta_archivos WHERE id = $va")->fetchColumn(), 'el registro debe quedar:');
});

prueba('D2-H03: un adjunto de venta no puede apuntar a un archivo de otro módulo (finanzas)', function () use ($empP, $adminP) {
    $dir = __DIR__ . '/../' . carpetaEmpresa('uploads') . '/finanzas';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $nombre = 'cobro_ajeno_' . bin2hex(random_bytes(3)) . '.pdf';
    file_put_contents("$dir/$nombre", '%PDF');
    $va = adjuntoVenta('../' . carpetaEmpresa('uploads') . "/finanzas/$nombre");
    llamar($empP, $adminP, 'Administrador', 'POST', 'backend/eliminar_archivo.php', ['json' => ['modulo' => 'ventas', 'id' => $va]]);
    $sigue = is_file("$dir/$nombre");
    @unlink("$dir/$nombre");
    como($empP, $adminP, 'Administrador');
    verdadero($sigue, 'se borró un comprobante de finanzas a través de un adjunto de venta');
});

prueba('D2-H06: un adjunto de orden con ruta ajena no borra otro archivo con el mismo nombre', function () use ($empP, $adminP) {
    global $pdo;
    $pdo->prepare("INSERT INTO incidencias (numero_incidente, cliente, contacto, fecha, categoria, estatus, falla) VALUES (?, 'C', 'x', CURDATE(), 'General', 'Abierto', 'x')")
        ->execute(['G-' . bin2hex(random_bytes(3))]);
    $orden = (int)$pdo->lastInsertId();
    $nombre = 'homonimo_' . bin2hex(random_bytes(3)) . '.jpg';
    $dir = __DIR__ . '/../' . carpetaEmpresa('uploads');
    file_put_contents("$dir/$nombre", 'archivo legítimo de otra orden');
    $pdo->prepare('INSERT INTO archivos_incidencias (incidencia_id, ruta_archivo) VALUES (?, ?)')->execute([$orden, "../uploads/e999999/$nombre"]);
    llamar($empP, $adminP, 'Administrador', 'POST', 'backend/eliminar_archivo.php', ['post' => ['id_incidencia' => $orden, 'url_archivo' => $nombre]]);
    $sigue = is_file("$dir/$nombre");
    @unlink("$dir/$nombre");
    como($empP, $adminP, 'Administrador');
    verdadero($sigue, 'se borró el archivo homónimo de la empresa');
});

prueba('D2-H07: el registro de un adjunto de venta cuya carpeta ya no existe se puede quitar', function () use ($empP, $adminP) {
    global $pdo;
    $va = adjuntoVenta('../' . carpetaEmpresa('uploads') . '/ventas/CARPETA_BORRADA_' . bin2hex(random_bytes(3)) . '/f.pdf');
    $r = llamar($empP, $adminP, 'Administrador', 'POST', 'backend/eliminar_archivo.php', ['json' => ['modulo' => 'ventas', 'id' => $va]]);
    como($empP, $adminP, 'Administrador');
    igual(200, $r['http'], 'respuesta (' . mb_substr($r['texto'], 0, 100) . '):');
    igual(0, (int)$pdo->query("SELECT COUNT(*) FROM venta_archivos WHERE id = $va")->fetchColumn(), 'registros que quedan:');
});

prueba('D2-H02: eliminar archivos solo acepta POST', function () use ($empP, $adminP) {
    $va = adjuntoVenta('../' . carpetaEmpresa('uploads') . '/ventas/X/no.pdf');
    $r = llamar($empP, $adminP, 'Administrador', 'GET', 'backend/eliminar_archivo.php', ['json' => ['modulo' => 'ventas', 'id' => $va]]);
    como($empP, $adminP, 'Administrador');
    igual(405, $r['http'], 'respuesta a GET:');
});

prueba('D2-H04: imágenes, PDF, audio, video y texto se abren en el visor; XML, SVG y HTML se descargan', function () {
    require_once __DIR__ . '/../backend/lib/subidas.php';
    $si = [['foto.jpg', 'image/jpeg'], ['plano.bmp', 'image/x-ms-bmp'], ['f.pdf', 'application/pdf'], ['v.mp4', 'video/mp4'],
           ['a.ogg', 'application/ogg'], ['nota.txt', 'text/x-c'], ['vacio.txt', 'application/x-empty'], ['datos.csv', 'application/csv']];
    foreach ($si as [$n, $t]) verdadero(seMuestraEnNavegador($n, $t), "$n ($t) debería verse en el visor");
    $no = [['factura.xml', 'text/xml'], ['x.xml', 'application/xhtml+xml'], ['dibujo.svg', 'image/svg+xml'],
           ['truco.txt', 'text/html'], ['truco.jpg', 'image/svg+xml'], ['libro.xlsx', 'application/vnd.ms-excel'], ['a.zip', 'application/zip']];
    foreach ($no as [$n, $t]) verdadero(!seMuestraEnNavegador($n, $t), "$n ($t) no debe abrirse en el navegador");
});

prueba('Archivos: con permiso de ventas se borra un adjunto legítimo de una venta', function () use ($empP, $adminP) {
    global $pdo;
    $dir = __DIR__ . '/../' . carpetaEmpresa('uploads') . '/ventas/CLIENTE_PRUEBA';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $nombre = 'VT-T_' . time() . '_' . bin2hex(random_bytes(2)) . '.pdf';
    file_put_contents("$dir/$nombre", '%PDF-1.4');
    $ruta = '../' . carpetaEmpresa('uploads') . "/ventas/CLIENTE_PRUEBA/$nombre";  // como lo guarda registro_ventas.php
    $pdo->prepare("INSERT INTO ventas (folio, cliente, fecha_registro) VALUES (?, 'CLIENTE PRUEBA', CURDATE())")->execute(['VT-T' . bin2hex(random_bytes(3))]);
    $venta = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO venta_archivos (venta_id, ruta_archivo, nombre_archivo) VALUES (?, ?, 'factura.pdf')")->execute([$venta, $ruta]);
    $va = (int)$pdo->lastInsertId();
    $r = llamar($empP, $adminP, 'Administrador', 'POST', 'backend/eliminar_archivo.php', ['json' => ['modulo' => 'ventas', 'id' => $va, 'ruta' => $ruta]]);
    $sigue = is_file("$dir/$nombre");
    @unlink("$dir/$nombre");
    como($empP, $adminP, 'Administrador');
    igual(200, $r['http'], 'respuesta (' . mb_substr($r['texto'], 0, 120) . '):');
    verdadero(!$sigue, 'el adjunto de la venta no se borró');
    igual(0, (int)$pdo->query("SELECT COUNT(*) FROM venta_archivos WHERE id = $va")->fetchColumn(), 'registro del adjunto:');
});

prueba('Archivos: con permiso de incidencias se borra un adjunto legítimo de una orden', function () use ($empP, $adminP) {
    global $pdo;
    $pdo->prepare("INSERT INTO incidencias (numero_incidente, cliente, contacto, fecha, categoria, estatus, falla) VALUES (?, 'C', 'x', CURDATE(), 'General', 'Abierto', 'x')")
        ->execute(['F-' . bin2hex(random_bytes(3))]);
    $orden = (int)$pdo->lastInsertId();
    $dir = __DIR__ . '/../' . carpetaEmpresa('uploads');
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $nombre = 'inc' . $orden . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '_foto.jpg';
    file_put_contents("$dir/$nombre", 'jpg');
    $pdo->prepare('INSERT INTO archivos_incidencias (incidencia_id, ruta_archivo) VALUES (?, ?)')->execute([$orden, '../' . carpetaEmpresa('uploads') . "/$nombre"]);
    $r = llamar($empP, $adminP, 'Administrador', 'POST', 'backend/eliminar_archivo.php', ['post' => ['id_incidencia' => $orden, 'url_archivo' => '../' . carpetaEmpresa('uploads') . "/$nombre"]]);
    $sigue = is_file("$dir/$nombre");
    @unlink("$dir/$nombre");
    como($empP, $adminP, 'Administrador');
    igual(200, $r['http'], 'respuesta (' . mb_substr($r['texto'], 0, 120) . '):');
    verdadero(!$sigue, 'el adjunto de la orden no se borró');
});

prueba('Archivos: un error al borrar no revela rutas internas del servidor', function () use ($empP, $adminP) {
    global $pdo;
    $pdo->prepare("INSERT INTO ventas (folio, cliente, fecha_registro) VALUES (?, 'CLIENTE PRUEBA', CURDATE())")->execute(['VT-T' . bin2hex(random_bytes(3))]);
    $venta = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO venta_archivos (venta_id, ruta_archivo, nombre_archivo) VALUES (?, '../uploads/no-existe.pdf', 'x.pdf')")->execute([$venta]);
    $va = (int)$pdo->lastInsertId();
    $r = llamar($empP, $adminP, 'Administrador', 'POST', 'backend/eliminar_archivo.php', ['json' => ['modulo' => 'ventas', 'id' => $va, 'ruta' => 'x']]);
    como($empP, $adminP, 'Administrador');
    verdadero(!isset($r['json']['searched_path']) && strpos($r['texto'], 'SERVIDESK') === false, 'la respuesta incluye una ruta del servidor: ' . mb_substr($r['texto'], 0, 160));
});

prueba('Archivos: los logos de la empresa los ve cualquier usuario de la empresa', function () use ($empP, $tecP, $adminP) {
    $dir = __DIR__ . '/../uploads/e' . $empP . '/marca';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $nombre = 'logo_' . bin2hex(random_bytes(3)) . '.png';
    file_put_contents("$dir/$nombre", 'png');
    $r = llamar($empP, $tecP, 'Técnico', 'GET', 'backend/archivo.php', ['get' => ['ruta' => "uploads/e$empP/marca/$nombre"]]);
    @unlink("$dir/$nombre");
    como($empP, $adminP, 'Administrador');
    igual(200, $r['http'], 'logo para el Técnico:');
});

