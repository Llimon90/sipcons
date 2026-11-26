<?php
// Marca de cada empresa (empresas.marca): la edita la plataforma desde su panel
// y, si su plan lo incluye (planes.permite_marca, migración 005), el propio
// administrador de la empresa desde Mi empresa > Personalización.
//
// Los logos se guardan en uploads/e<ID>/marca/ y se sirven por
// backend/archivo.php, que exige sesión de esa misma empresa.
require_once __DIR__ . '/plataforma.php';

const EXTENSIONES_LOGO = ['png', 'jpg', 'jpeg', 'webp'];
const MAX_BYTES_LOGO = 1024 * 1024;
const MAX_CATEGORIAS = 30;

function planesTienenPermiteMarca(PDO $pdo): bool {
    static $existe = null;
    if ($existe === null) {
        try {
            $existe = (bool)$pdo->query("SHOW COLUMNS FROM planes LIKE 'permite_marca'")->fetch();
        } catch (Throwable $e) {
            $existe = false;
        }
    }
    return $existe;
}

/** ¿El cliente puede personalizar su marca? (la demo nunca; sin la migración 005, todos) */
function marcaPersonalizable(PDO $pdo, ?array $e): bool {
    // La prueba gratuita incluye todo el sistema, sin importar el plan asignado
    if (($e['estado'] ?? '') === 'prueba') return true;
    if (!$e || $e['estado'] === 'demo') return false;
    if (!planesTienenPermiteMarca($pdo)) return true;
    $plan = planPorId($pdo, $e['plan_id'] ? (int)$e['plan_id'] : null);
    return !$plan || (int)($plan['permite_marca'] ?? 1) === 1;
}

/**
 * Valida y guarda los cambios de marca (solo las claves presentes en $d):
 * nombre, eslogan, prefijo_folio, zona_horaria, colores{primario,secundario,sidebar},
 * categorias (arreglo o texto con una por línea). Devuelve [antes, después].
 */
function guardarMarcaEmpresa(PDO $pdo, int $empresaId, array $d): array {
    $empresa = empresaPorId($pdo, $empresaId);
    if (!$empresa) throw new InvalidArgumentException('Empresa no encontrada.');
    $antes = $empresa['marca'];
    $marca = $antes;
    foreach (['nombre' => 100, 'eslogan' => 150] as $k => $max) {
        if (array_key_exists($k, $d)) $marca[$k] = mb_substr(trim((string)$d[$k]), 0, $max);
    }
    if (array_key_exists('nombre', $d) && $marca['nombre'] === '') throw new InvalidArgumentException('El nombre visible no puede quedar vacío.');
    if (array_key_exists('prefijo_folio', $d)) {
        $prefijo = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$d['prefijo_folio']));
        if ($prefijo === '' || strlen($prefijo) > 6) throw new InvalidArgumentException('El prefijo de folio lleva de 1 a 6 letras o números.');
        $marca['prefijo_folio'] = $prefijo;
    }
    if (array_key_exists('zona_horaria', $d)) {
        $zona = trim((string)$d['zona_horaria']);
        // S-H02: sin zona, la empresa quedaría con la hora de quien la usó antes en el mismo proceso
        if ($zona === '' || !in_array($zona, DateTimeZone::listIdentifiers(), true)) throw new InvalidArgumentException('Elige una zona horaria válida.');
        $marca['zona_horaria'] = $zona;
    }
    foreach (['primario', 'secundario', 'sidebar'] as $c) {
        if (!empty($d['colores'][$c])) {
            if (!preg_match('/^#[0-9a-f]{6}$/i', (string)$d['colores'][$c])) throw new InvalidArgumentException('Color no válido: ' . $c);
            $marca['colores'][$c] = strtolower($d['colores'][$c]);
        }
    }
    if (array_key_exists('categorias', $d)) {
        $lista = is_array($d['categorias']) ? $d['categorias'] : explode("\n", (string)$d['categorias']);
        $cats = array_values(array_unique(array_filter(array_map(fn($c) => mb_substr(trim((string)$c), 0, 50), $lista), 'strlen')));
        if (!$cats) throw new InvalidArgumentException('Debe haber al menos una categoría.');
        $marca['categorias'] = array_slice($cats, 0, MAX_CATEGORIAS);
    }
    $pdo->prepare("UPDATE empresas SET marca = ?, actualizado_en = NOW() WHERE id = ?")
        ->execute([json_encode($marca, JSON_UNESCAPED_UNICODE), $empresaId]);
    return [$antes, $marca];
}

/** Carpeta de logos de una empresa, relativa a la raíz del sitio. */
function carpetaLogos(int $empresaId): string {
    return 'uploads/e' . $empresaId . '/marca';
}

/** Borra el logo propio de la empresa (nunca uno de la plataforma, como img/logo.svg). */
function borrarLogoPropio(int $empresaId, ?string $logo): void {
    if (!$logo || strpos($logo, carpetaLogos($empresaId) . '/') !== 0) return;
    $ruta = realpath(__DIR__ . '/../' . $logo);
    $base = realpath(__DIR__ . '/../' . carpetaLogos($empresaId));
    if ($ruta && $base && strpos($ruta, $base . DIRECTORY_SEPARATOR) === 0) @unlink($ruta);
}

/** Guarda un logo subido ($_FILES) y lo deja como logo de la empresa. Devuelve su ruta. */
function guardarLogoEmpresa(PDO $pdo, int $empresaId, array $archivo): string {
    $empresa = empresaPorId($pdo, $empresaId);
    if (!$empresa) throw new InvalidArgumentException('Empresa no encontrada.');
    if (($archivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($archivo['tmp_name'] ?? '')) {
        throw new InvalidArgumentException(($archivo['error'] ?? 0) === UPLOAD_ERR_INI_SIZE ? 'La imagen pesa más de 1 MB.' : 'No se recibió la imagen.');
    }
    $ext = strtolower(pathinfo((string)$archivo['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, EXTENSIONES_LOGO, true)) throw new InvalidArgumentException('Sube el logo en PNG, JPG o WEBP.');
    if ((int)$archivo['size'] > MAX_BYTES_LOGO) throw new InvalidArgumentException('La imagen pesa más de 1 MB.');
    $info = @getimagesize($archivo['tmp_name']);
    $tipos = [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP];
    if (!$info || !in_array($info[2], $tipos, true)) throw new InvalidArgumentException('El archivo no es una imagen válida.');
    if ($info[0] < 32 || $info[1] < 32) throw new InvalidArgumentException('La imagen es demasiado pequeña (mínimo 32 × 32 píxeles).');

    $dir = __DIR__ . '/../' . carpetaLogos($empresaId);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) throw new RuntimeException('No se pudo crear la carpeta del logo.');
    $nombre = 'logo_' . bin2hex(random_bytes(6)) . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
    if (!move_uploaded_file($archivo['tmp_name'], "$dir/$nombre")) throw new RuntimeException('No se pudo guardar el logo.');

    $anterior = $empresa['marca']['logo'] ?? null;
    $marca = $empresa['marca'];
    $marca['logo'] = carpetaLogos($empresaId) . '/' . $nombre;
    $pdo->prepare("UPDATE empresas SET marca = ?, actualizado_en = NOW() WHERE id = ?")
        ->execute([json_encode($marca, JSON_UNESCAPED_UNICODE), $empresaId]);
    borrarLogoPropio($empresaId, $anterior);
    return $marca['logo'];
}

/** Vuelve al logo de la plataforma. */
function quitarLogoEmpresa(PDO $pdo, int $empresaId): void {
    $empresa = empresaPorId($pdo, $empresaId);
    if (!$empresa) throw new InvalidArgumentException('Empresa no encontrada.');
    $marca = $empresa['marca'];
    $anterior = $marca['logo'] ?? null;
    $marca['logo'] = marcaPorDefecto($empresa['nombre'])['logo'];
    $pdo->prepare("UPDATE empresas SET marca = ?, actualizado_en = NOW() WHERE id = ?")
        ->execute([json_encode($marca, JSON_UNESCAPED_UNICODE), $empresaId]);
    borrarLogoPropio($empresaId, $anterior);
}

/** Restablece la apariencia (nombre, eslogan, logo, colores); conserva folio, categorías y zona horaria. */
function restablecerMarcaEmpresa(PDO $pdo, int $empresaId): array {
    $empresa = empresaPorId($pdo, $empresaId);
    if (!$empresa) throw new InvalidArgumentException('Empresa no encontrada.');
    $antes = $empresa['marca'];
    $base = marcaPorDefecto($empresa['nombre']);
    $marca = array_replace($antes, array_intersect_key($base, array_flip(['nombre', 'eslogan', 'logo', 'colores'])));
    $pdo->prepare("UPDATE empresas SET marca = ?, actualizado_en = NOW() WHERE id = ?")
        ->execute([json_encode($marca, JSON_UNESCAPED_UNICODE), $empresaId]);
    borrarLogoPropio($empresaId, $antes['logo'] ?? null);
    return [$antes, $marca];
}
