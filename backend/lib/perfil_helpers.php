<?php
// Perfil del usuario (D-46): datos, foto, métricas y órdenes activas.
//  - La foto se recodifica con GD (JPEG, máx. 512 px): así se quitan EXIF, GPS y cualquier
//    contenido incrustado. Se guarda en uploads/e<ID>/usuarios/ (la sirve archivo.php con sesión).
//  - Cada usuario cambia solo SU foto: el endpoint toma el id de la sesión, nunca de la petición.
require_once __DIR__ . '/../../config/empresa.php';
require_once __DIR__ . '/incidencias_helpers.php';  // sqlOrdenActiva, ordenAtrasada (D-4, D-18)

const PERFIL_FOTO_MAX_BYTES = 1024 * 1024;
const PERFIL_FOTO_LADO = 512;

/** ¿La tabla usuarios tiene la columna foto (migración 016)? */
function perfilTieneFoto(PDO $pdo): bool {
    static $hay = null;
    if ($hay === null) {
        try { $hay = (bool)$pdo->query("SHOW COLUMNS FROM usuarios LIKE 'foto'")->fetch(); } catch (Throwable $e) { $hay = false; }
    }
    return $hay;
}

/** Recodifica la imagen de $origen y la guarda como foto del usuario. Devuelve la ruta relativa. */
function perfilGuardarFoto(PDO $pdo, int $usuarioId, string $origen): string {
    if (!perfilTieneFoto($pdo)) throw new RuntimeException('Falta aplicar la migración 016 (foto de perfil).');
    if (!is_file($origen)) throw new InvalidArgumentException('No se recibió la foto. Inténtalo de nuevo.');
    if (filesize($origen) > PERFIL_FOTO_MAX_BYTES) throw new InvalidArgumentException('La foto pesa más de 1 MB. Elige una más ligera.');
    $info = @getimagesize($origen);
    $tipos = [IMAGETYPE_JPEG => 'imagecreatefromjpeg', IMAGETYPE_PNG => 'imagecreatefrompng', IMAGETYPE_WEBP => 'imagecreatefromwebp'];
    $abrir = $info ? ($tipos[$info[2]] ?? null) : null;
    if (!$abrir || !function_exists($abrir)) throw new InvalidArgumentException('La foto debe ser una imagen JPG, PNG o WEBP.');
    [$ancho, $alto] = $info;
    if ($ancho < 1 || $alto < 1 || $ancho * $alto > 40000000) throw new InvalidArgumentException('La imagen no es válida.');
    $img = @$abrir($origen);
    if (!$img) throw new InvalidArgumentException('La imagen está dañada.');

    $escala = min(1, PERFIL_FOTO_LADO / max($ancho, $alto));
    $nuevoAncho = max(1, (int)round($ancho * $escala));
    $nuevoAlto = max(1, (int)round($alto * $escala));
    $lienzo = imagecreatetruecolor($nuevoAncho, $nuevoAlto);
    imagefill($lienzo, 0, 0, imagecolorallocate($lienzo, 255, 255, 255));  // PNG transparente sobre blanco
    imagecopyresampled($lienzo, $img, 0, 0, 0, 0, $nuevoAncho, $nuevoAlto, $ancho, $alto);
    imagedestroy($img);

    $relativa = carpetaEmpresa('uploads') . '/usuarios';
    $dir = __DIR__ . '/../../' . $relativa;
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) throw new RuntimeException('No se pudo crear la carpeta de fotos.');
    $nombre = 'u' . $usuarioId . '_' . bin2hex(random_bytes(6)) . '.jpg';
    if (!imagejpeg($lienzo, "$dir/$nombre", 85)) throw new RuntimeException('No se pudo guardar la foto.');
    imagedestroy($lienzo);

    $st = $pdo->prepare('SELECT foto FROM usuarios WHERE id = ? AND empresa_id = ?');
    $st->execute([$usuarioId, EmpresaContexto::$id]);
    $anterior = $st->fetchColumn();
    $pdo->prepare('UPDATE usuarios SET foto = ? WHERE id = ? AND empresa_id = ?')->execute(["$relativa/$nombre", $usuarioId, EmpresaContexto::$id]);
    perfilBorrarArchivo($anterior ?: null);
    return "$relativa/$nombre";
}

function perfilQuitarFoto(PDO $pdo, int $usuarioId): void {
    if (!perfilTieneFoto($pdo)) return;
    $st = $pdo->prepare('SELECT foto FROM usuarios WHERE id = ? AND empresa_id = ?');
    $st->execute([$usuarioId, EmpresaContexto::$id]);
    $anterior = $st->fetchColumn();
    $pdo->prepare('UPDATE usuarios SET foto = NULL WHERE id = ? AND empresa_id = ?')->execute([$usuarioId, EmpresaContexto::$id]);
    perfilBorrarArchivo($anterior ?: null);
}

/** Borra una foto de perfil de la empresa activa (solo dentro de su carpeta). */
function perfilBorrarArchivo(?string $ruta): void {
    if (!$ruta || strpos($ruta, carpetaEmpresa('uploads') . '/usuarios/') !== 0 || strpos($ruta, '..') !== false) return;
    $archivo = __DIR__ . '/../../' . $ruta;
    if (is_file($archivo)) @unlink($archivo);
}

/**
 * Perfil de un usuario de la empresa activa: datos (sin contraseña), métricas y órdenes
 * activas donde aparece como técnico. Null si no es de la empresa.
 */
/**
 * L-25: órdenes cerradas en el periodo según CUÁNDO se cerraron (último cambio a Cerrado en el
 * historial), no según su fecha de reporte. Sin historial, se usa la fecha de reporte.
 */
function perfilCerradasDesde(PDO $pdo, array $ordenes, string $desde): int {
    $cerradas = array_values(array_filter($ordenes, fn($f) => in_array($f['estatus'], ['Cerrado sin factura', 'Cerrado con factura'], true)));
    if (!$cerradas) return 0;
    $cuando = [];
    try {
        $ids = array_map(fn($f) => (string)$f['id'], $cerradas);
        $st = $pdo->prepare("SELECT registro_id, MAX(creado_en) FROM auditoria WHERE tabla = 'incidencias' AND registro_id IN ("
            . implode(',', array_fill(0, count($ids), '?')) . ") AND datos_nuevos REGEXP '\"estatus\": ?\"Cerrado'
            AND (datos_anteriores IS NULL OR datos_anteriores NOT REGEXP '\"estatus\": ?\"Cerrado') GROUP BY registro_id");
        $st->execute($ids);
        $cuando = $st->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (Throwable $e) { /* sin historial */ }
    return count(array_filter($cerradas, fn($f) => substr((string)($cuando[(string)$f['id']] ?? $f['fecha']), 0, 10) >= $desde));
}

function perfilVer(PDO $pdo, int $usuarioId): ?array {
    $cols = 'id, nombre, correo, telefono, rol, fecha_creacion' . (perfilTieneFoto($pdo) ? ', foto' : '');
    try {
        $st = $pdo->prepare("SELECT $cols, alias_tecnico FROM usuarios WHERE id = ? AND empresa_id = ?");
        $st->execute([$usuarioId, EmpresaContexto::$id]);
    } catch (Throwable $e) {
        $st = $pdo->prepare("SELECT $cols FROM usuarios WHERE id = ? AND empresa_id = ?");
        $st->execute([$usuarioId, EmpresaContexto::$id]);
    }
    $u = $st->fetch(PDO::FETCH_ASSOC);
    if (!$u) return null;
    $identidad = trim((string)($u['alias_tecnico'] ?? '')) ?: trim((string)$u['nombre']);
    unset($u['alias_tecnico']);
    $u['id'] = (int)$u['id'];
    $u['foto'] = $u['foto'] ?? null;

    // Órdenes donde aparece (prefiltro LIKE, decisión exacta con tecnicoCoincide)
    $st = $pdo->prepare("SELECT id, numero_incidente, cliente, sucursal, falla, tecnico, estatus, fecha FROM incidencias
                         WHERE tecnico LIKE ? ORDER BY fecha DESC, id DESC");
    $st->execute(['%' . addcslashes($identidad, '%_' . chr(92)) . '%']);
    $suyas = array_values(array_filter($st->fetchAll(PDO::FETCH_ASSOC), fn($f) => tecnicoCoincide($identidad, $f['tecnico'] ?? '')));

    $hoy = date('Y-m-d');
    $hace30 = date('Y-m-d', strtotime('-30 days'));
    $esActiva = fn($f) => !in_array($f['estatus'], ESTATUS_FINALES, true) && (!$f['fecha'] || substr($f['fecha'], 0, 10) <= $hoy);
    $activas = array_values(array_filter($suyas, $esActiva));
    $metricas = [
        'activas' => count($activas),
        'atrasadas' => count(array_filter($activas, fn($f) => ordenAtrasada($f['fecha'], $f['estatus']))),
        'completadas_por_cerrar' => count(array_filter($activas, fn($f) => $f['estatus'] === 'Completado')),
        'programadas' => count(array_filter($suyas, fn($f) => !in_array($f['estatus'], ESTATUS_FINALES, true) && $f['estatus'] !== 'Completado' && $f['fecha'] && substr($f['fecha'], 0, 10) > $hoy)),  // H-04 · L-34
        'cerradas_30_dias' => perfilCerradasDesde($pdo, $suyas, $hace30),  // L-25: por la fecha de cierre
        'total_historico' => count($suyas),
    ];
    $activas = array_map(fn($f) => ['id' => (int)$f['id'], 'falla' => mb_strimwidth(trim((string)$f['falla']), 0, 90, '…'),
        'atrasada' => ordenAtrasada($f['fecha'], $f['estatus'])] + array_diff_key($f, ['tecnico' => 1]), array_slice($activas, 0, 50));
    return ['usuario' => $u, 'identidad' => $identidad, 'metricas' => $metricas, 'activas' => $activas];
}
