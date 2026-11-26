<?php
// Entrega archivos subidos (uploads/, manuales/) solo a usuarios de la empresa
// dueña. El .htaccess de la raíz envía aquí toda petición a /uploads/... o
// /manuales/..., así que las URLs de la interfaz no cambian pero ya no se
// puede abrir un archivo sin sesión ni el de otra empresa.
//   uploads/e7/ventas/...  -> solo usuarios de la empresa 7
require_once __DIR__ . '/../auth/middleware.php';

function noEncontrado(): void {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    die('Archivo no encontrado');
}

$ruta = str_replace('\\', '/', (string)($_GET['ruta'] ?? ''));
$ruta = preg_replace('#^(\.\./|\./|/)+#', '', $ruta);

if (!preg_match('#^(uploads|manuales)/e(\d+)/.+#', $ruta, $m)
    || (int)$m[2] !== (int)($_SESSION['empresa_id'] ?? 0)) {
    noEncontrado();
}

$base = realpath(__DIR__ . '/../' . $m[1] . '/e' . $m[2]);
$archivo = realpath(__DIR__ . '/../' . $ruta);
if (!$base || !$archivo || !is_file($archivo) || strpos($archivo, $base . DIRECTORY_SEPARATOR) !== 0) {
    noEncontrado();
}

// Además de ser de la empresa, el archivo exige el módulo al que pertenece
// (auditorias/2026-11-10-permisos-triage.md, P-H02). La carpeta se toma de la
// ruta YA RESUELTA por realpath: con la ruta cruda, "e7//finanzas" o
// "e7/marca/../finanzas" se saltaban el permiso. Responde 404 como si no
// existiera, para no confirmar qué archivos hay.
require_once __DIR__ . '/../auth/permisos.php';
$subruta = str_replace('\\', '/', substr($archivo, strlen($base) + 1));
$carpeta = strpos($subruta, '/') !== false ? strstr($subruta, '/', true) : '';
$modulosPermitidos = $m[1] === 'manuales' ? ['soporte'] : match ($carpeta) {
    'marca'      => null,                                   // logo de la empresa: cualquier usuario
    'usuarios'   => null,                                   // F-1 · D-46: fotos de perfil, cualquier usuario de la empresa
    'ventas'     => ['ventas'],
    'finanzas'   => ['finanzas'],
    'inventario' => ['inventario', 'incidencias', 'cotizaciones', 'ventas'],
    ''           => ['incidencias', 'reportes'],             // adjuntos de órdenes de servicio
    default      => ['__ninguno__'],
};
if ($modulosPermitidos !== null && !array_filter($modulosPermitidos, 'tienePermiso')) {
    noEncontrado();
}
// D-9: un adjunto de orden solo lo ve el Técnico asignado a esa orden
if ($m[1] === 'uploads' && $carpeta === '') {
    require_once __DIR__ . '/lib/incidencias_helpers.php';
    if (soloOrdenesPropias()) {
        $nombre = basename($subruta);
        $st = $pdo->prepare('SELECT a.ruta_archivo, i.tecnico FROM archivos_incidencias a JOIN incidencias i ON i.id = a.incidencia_id WHERE a.ruta_archivo LIKE ?');
        $st->execute(['%' . addcslashes($nombre, '%_' . chr(92))]);
        $suyo = false;
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $f) {
            $rutaFila = realpath(__DIR__ . '/../' . preg_replace('#^(\.\./|\./|/)+#', '', str_replace(chr(92), '/', $f['ruta_archivo'])));
            if ($rutaFila === $archivo && ordenVisibleParaSesion($pdo, $f['tecnico'])) { $suyo = true; break; }
        }
        if (!$suyo) noEncontrado();
    }
}

$tipo = (function_exists('mime_content_type') ? @mime_content_type($archivo) : false) ?: 'application/octet-stream';
// Nunca interpretar como HTML/SVG/JS/XML algo subido por un usuario: solo se abre en el
// navegador lo que es seguro (seMuestraEnNavegador); lo demás se descarga.
require_once __DIR__ . '/lib/subidas.php';
$enNavegador = seMuestraEnNavegador(basename($archivo), $tipo) && !isset($_GET['descargar']);
if (tipoEjecutable($tipo)) {
    $tipo = 'application/octet-stream';
} elseif ($enNavegador && in_array(strtolower(pathinfo($archivo, PATHINFO_EXTENSION)), ['txt', 'csv', 'log'], true)) {
    // libmagic a veces los clasifica como text/x-c, application/csv o x-empty
    $tipo = 'text/plain; charset=utf-8';
}

// La sesión ya se validó: liberarla deja que el navegador pida varios
// archivos a la vez (miniaturas, visor) sin que uno espere a otro.
session_write_close();

$tamano = filesize($archivo);
$modificado = filemtime($archivo);
$etag = '"' . dechex($modificado) . '-' . dechex($tamano) . '"';

// Caché en el navegador del usuario (privada: nunca en proxies compartidos).
//  - Nombres únicos (inc<ID>_<fecha>_<hora>_<aleatorio>_...): no cambian nunca,
//    se guardan 30 días.
//  - Los demás (antiguos o de ventas, que podrían reemplazarse con el mismo
//    nombre): el navegador pregunta si cambiaron y, si no, no los descarga.
header_remove('Pragma');
header_remove('Expires');
header('Cache-Control: ' . (preg_match('/^inc\d+_\d{8}_\d{6}_[0-9a-f]{6}_/', basename($archivo))
    ? 'private, max-age=2592000, immutable'
    : 'private, no-cache'));
header('ETag: ' . $etag);
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $modificado) . ' GMT');
header('Accept-Ranges: bytes');
header('X-Content-Type-Options: nosniff');

$siNoCoincide = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
$siModificado = $_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '';
if (($siNoCoincide !== '' && trim($siNoCoincide) === $etag)
    || ($siNoCoincide === '' && $siModificado !== '' && strtotime($siModificado) >= $modificado)) {
    http_response_code(304);
    exit;
}

header_remove('Content-Type');
header('Content-Type: ' . $tipo);
header('Content-Disposition: ' . ($enNavegador ? 'inline' : 'attachment') . '; filename="' . rawurlencode(basename($archivo)) . '"');

// Descarga por partes (un solo rango): el visor de PDF pide solo las páginas
// que va a dibujar en vez del documento completo.
$inicio = 0;
$fin = $tamano - 1;
if (preg_match('/^bytes=(\d*)-(\d*)$/', trim($_SERVER['HTTP_RANGE'] ?? ''), $r)
    && ($r[1] !== '' || $r[2] !== '')
    && (($_SERVER['HTTP_IF_RANGE'] ?? '') === '' || $_SERVER['HTTP_IF_RANGE'] === $etag)) {
    if ($r[1] === '') {
        $inicio = max(0, $tamano - (int)$r[2]);
    } else {
        $inicio = (int)$r[1];
        if ($r[2] !== '') $fin = min((int)$r[2], $tamano - 1);
    }
    if ($inicio > $fin || $inicio >= $tamano) {
        http_response_code(416);
        header("Content-Range: bytes */$tamano");
        exit;
    }
    http_response_code(206);
    header("Content-Range: bytes $inicio-$fin/$tamano");
}

$largo = $fin - $inicio + 1;
header('Content-Length: ' . $largo);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') exit;

if ($inicio === 0 && $largo === $tamano) {
    readfile($archivo);
    exit;
}
$fp = fopen($archivo, 'rb');
fseek($fp, $inicio);
while ($largo > 0 && !feof($fp)) {
    $bloque = fread($fp, min(65536, $largo));
    if ($bloque === false || $bloque === '') break;
    echo $bloque;
    $largo -= strlen($bloque);
    flush();
}
fclose($fp);
