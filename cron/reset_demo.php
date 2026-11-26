<?php
// Restablece la EMPRESA DEMO (id 1, estado 'demo'): recarga database/seed.sql,
// que borra y vuelve a crear solo los datos de esa empresa (tablas e1_* y sus
// usuarios), vacía sus archivos (uploads/e1, manuales/e1) y restaura los
// documentos de ejemplo. Nunca toca a otras empresas.
//
// Solo por línea de comandos y solo con DEMO_MODE=true: en la instancia de un
// cliente real se niega a correr, así que un cron mal copiado no borra nada.
//
// Hostinger (hPanel > Avanzado > Cron Jobs), cada hora:
//   0 * * * *   /usr/bin/php /home/USUARIO/domains/DOMINIO/public_html/cron/reset_demo.php
// El script solo actúa cuando en APP_TIMEZONE es la hora de DEMO_RESET_HORA
// (03:00 por defecto); así no importa en qué huso corre el cron del servidor.
// Para restablecer en el momento:  php cron/reset_demo.php --forzar

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/demo.php';

function salir(string $mensaje, int $codigo = 0): void {
    fwrite($codigo ? STDERR : STDOUT, '[' . date('Y-m-d H:i:s') . "] $mensaje\n");
    exit($codigo);
}

if (!demoActivo()) {
    salir('DEMO_MODE no está activo en .env: no se restablece nada.', 1);
}

$forzar = in_array('--forzar', $argv, true);
$horaReset = (int)substr((string)env('DEMO_RESET_HORA', '03:00'), 0, 2);
if (!$forzar && (int)date('G') !== $horaReset) {
    exit(0); // fuera de la hora programada: silencio (el cron corre cada hora)
}

$seed = __DIR__ . '/../database/seed.sql';
if (!is_readable($seed)) {
    salir("No se encontró $seed", 1);
}

const EMPRESA_DEMO = 1; // el seed está generado para la empresa 1 (tablas e1_*)

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $conn = new mysqli(
        env('DB_HOST', 'localhost'), env('DB_USER', ''), env('DB_PASS', ''),
        env('DB_NAME', ''), (int)env('DB_PORT', 3306)
    );
    $conn->set_charset('utf8mb4');

    // Salvaguarda: la empresa 1 debe ser realmente la demo
    $estado = $conn->query("SELECT estado FROM empresas WHERE id = " . EMPRESA_DEMO)->fetch_column();
    if ($estado !== 'demo') {
        salir('La empresa ' . EMPRESA_DEMO . " no está en estado 'demo' (estado: " . var_export($estado, true) . '): no se restablece nada.', 1);
    }
    $conn->query("SET time_zone = '" . (new DateTime('now'))->format('P') . "'");

    // El seed es una serie de sentencias; multi_query exige consumir cada resultado
    $conn->multi_query(file_get_contents($seed));
    do {
        if ($r = $conn->store_result()) $r->free();
    } while ($conn->more_results() && $conn->next_result());
    $conn->close();
} catch (Throwable $e) {
    salir('Error al cargar el seed: ' . $e->getMessage(), 1);
}

// Vacía una carpeta de archivos de usuario conservando su .htaccess de protección
function vaciarCarpeta(string $ruta): int {
    $base = realpath($ruta);
    if (!$base) return 0;
    $borrados = 0;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        if ($f->getFilename() === '.htaccess' && $f->getPath() === $base) continue;
        $f->isDir() ? @rmdir($f->getPathname()) : (@unlink($f->getPathname()) && $borrados++);
    }
    return $borrados;
}

// Solo las carpetas de la empresa demo
$borrados = vaciarCarpeta(__DIR__ . '/../uploads/e' . EMPRESA_DEMO)
          + vaciarCarpeta(__DIR__ . '/../manuales/e' . EMPRESA_DEMO);

// Documentos de ejemplo del módulo Soporte (rutas manuales/e1/* en el seed)
$copiados = 0;
$origen = __DIR__ . '/../database/demo_docs';
$destino = __DIR__ . '/../manuales/e' . EMPRESA_DEMO;
if (is_dir($origen)) {
    @mkdir($destino, 0755, true);
    foreach (glob($origen . '/*.pdf') as $pdf) {
        if (copy($pdf, $destino . '/' . basename($pdf))) $copiados++;
    }
}

salir("Demo restablecida. Archivos eliminados: $borrados; documentos demo restaurados: $copiados");
