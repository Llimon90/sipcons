<?php
// Perfil del técnico (D-46): datos, foto, métricas y órdenes activas. Cada quien sube solo
// su foto; el Administrador ve el perfil de cualquier usuario de su empresa.
require_once __DIR__ . '/../backend/lib/perfil_helpers.php';

[$empF, $adminF] = empresaTemporal('Perfil');
[$empOtraF, $adminOtraF] = empresaTemporal('Perfil Otra');
$sxF = bin2hex(random_bytes(2));
$nomF = "Tecnico Perfil $sxF";
$tecF = usuarioTemporal($empF, 'Técnico', ['nombre' => $nomF]);
$tec2F = usuarioTemporal($empF, 'Técnico', ['nombre' => "Otro Perfil $sxF"]);
como($empF, $adminF, 'Administrador');
clienteCatalogo('Cliente Perfil');
$pdo->prepare("INSERT INTO incidencias (numero_incidente, cliente, contacto, sucursal, fecha, categoria, estatus, falla, tecnico) VALUES (?, 'Cliente Perfil', 'x', '', CURDATE(), 'General', 'Asignado', 'x', ?)")
    ->execute(['PF-' . $sxF, $nomF]);

/** JPEG de prueba con un comentario "secreto" incrustado (como los metadatos de un teléfono). */
function jpegConMetadatosF(int $lado = 64): string {
    $img = imagecreatetruecolor($lado, $lado);
    imagefill($img, 0, 0, imagecolorallocate($img, 200, 30, 30));
    ob_start();
    imagejpeg($img, null, 90);
    $jpg = ob_get_clean();
    $texto = 'GPS-SECRETO-19.4326,-99.1332';
    $com = "\xFF\xFE" . pack('n', strlen($texto) + 2) . $texto;  // segmento COM después de SOI
    $ruta = tempnam(sys_get_temp_dir(), 'foto') . '.jpg';
    file_put_contents($ruta, substr($jpg, 0, 2) . $com . substr($jpg, 2));
    return $ruta;
}

grupo('Perfil del técnico (D-46)');

prueba('D-46: la foto se recodifica en el servidor y pierde los metadatos', function () use ($empF, $tecF, $nomF) {
    global $pdo;
    como($empF, $tecF, 'Técnico', $nomF);
    $origen = jpegConMetadatosF();
    verdadero(str_contains(file_get_contents($origen), 'GPS-SECRETO'), 'la imagen de prueba no trae el metadato');
    $ruta = perfilGuardarFoto($pdo, $tecF, $origen);
    $archivo = __DIR__ . '/../' . $ruta;
    verdadero(is_file($archivo), "no se guardó la foto: $ruta");
    verdadero(!str_contains(file_get_contents($archivo), 'GPS-SECRETO'), 'la foto conserva los metadatos');
    igual($ruta, $pdo->query("SELECT foto FROM usuarios WHERE id = $tecF")->fetchColumn(), 'usuarios.foto:');
    verdadero(str_starts_with($ruta, carpetaEmpresa('uploads') . '/usuarios/'), "carpeta: $ruta");
    @unlink($origen);
});

prueba('D-46: no se acepta un archivo que no es imagen ni una foto de más de 1 MB', function () use ($empF, $tecF, $nomF) {
    global $pdo;
    como($empF, $tecF, 'Técnico', $nomF);
    $txt = tempnam(sys_get_temp_dir(), 'txt');
    file_put_contents($txt, '<?php echo 1; ?>');
    lanza(fn() => perfilGuardarFoto($pdo, $tecF, $txt), InvalidArgumentException::class, 'imagen');
    $grande = tempnam(sys_get_temp_dir(), 'big');
    file_put_contents($grande, str_repeat('x', PERFIL_FOTO_MAX_BYTES + 1));
    lanza(fn() => perfilGuardarFoto($pdo, $tecF, $grande), InvalidArgumentException::class, '1 MB');
    @unlink($txt);
    @unlink($grande);
});

prueba('D-46: quitar la foto por el endpoint solo quita la propia, aunque se mande otro id', function () use ($empF, $tecF, $tec2F, $nomF) {
    global $pdo;
    como($empF, $tec2F, 'Técnico');
    $origen = jpegConMetadatosF();
    $ruta2 = perfilGuardarFoto($pdo, $tec2F, $origen);
    @unlink($origen);
    $r = llamar($empF, $tecF, 'Técnico', 'POST', 'backend/perfil.php', ['get' => ['accion' => 'quitar_foto', 'id' => $tec2F], 'json' => ['id' => $tec2F]]);
    igual(200, $r['http'], 'quitar (' . mb_substr($r['texto'], 0, 120) . '):');
    igual($ruta2, $pdo->query("SELECT foto FROM usuarios WHERE id = $tec2F")->fetchColumn(), 'foto del otro técnico:');
    igual(null, $pdo->query("SELECT foto FROM usuarios WHERE id = $tecF")->fetchColumn(), 'foto propia:');
});

prueba('D-46: cada quien ve su perfil con métricas y órdenes activas', function () use ($empF, $tecF, $sxF) {
    $r = llamar($empF, $tecF, 'Técnico', 'GET', 'backend/perfil.php', ['get' => ['accion' => 'ver']]);
    igual(200, $r['http'], 'ver (' . mb_substr($r['texto'], 0, 160) . '):');
    igual((int)$tecF, (int)($r['json']['usuario']['id'] ?? 0), 'usuario:');
    igual(['PF-' . $sxF], array_column($r['json']['activas'] ?? [], 'numero_incidente'), 'órdenes activas:');
    igual(1, (int)($r['json']['metricas']['activas'] ?? -1), 'métrica de activas:');
    verdadero(!isset($r['json']['usuario']['password']), 'el perfil expone la contraseña');
});

prueba('D-46: el Administrador ve el perfil de un técnico; un Técnico no ve el de otro', function () use ($empF, $adminF, $tecF, $tec2F) {
    $r = llamar($empF, $adminF, 'Administrador', 'GET', 'backend/perfil.php', ['get' => ['accion' => 'ver', 'id' => $tecF]]);
    igual((int)$tecF, (int)($r['json']['usuario']['id'] ?? 0), 'el administrador ve al técnico:');
    $r = llamar($empF, $tec2F, 'Técnico', 'GET', 'backend/perfil.php', ['get' => ['accion' => 'ver', 'id' => $tecF]]);
    igual(403, $r['http'], 'un técnico ve el perfil de otro:');
});

prueba('MT: el Administrador no ve el perfil de un usuario de otra empresa', function () use ($empOtraF, $adminOtraF, $tecF) {
    $r = llamar($empOtraF, $adminOtraF, 'Administrador', 'GET', 'backend/perfil.php', ['get' => ['accion' => 'ver', 'id' => $tecF]]);
    verdadero($r['http'] === 404 || $r['http'] === 403, "vio un perfil de otra empresa (HTTP {$r['http']})");
});
