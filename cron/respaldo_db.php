<?php
// Respaldo diario de la base de datos en un .sql.gz importable desde phpMyAdmin
// (Importar > archivo .sql.gz) o con: gunzip -c archivo.sql.gz | mysql BASE
//
// PHP puro (no depende de mysqldump). Solo por línea de comandos.
// Hostinger (hPanel > Avanzado > Cron Jobs), cada hora:
//   0 * * * *   /usr/bin/php /home/USUARIO/domains/DOMINIO/public_html/cron/respaldo_db.php
// Actúa solo a la hora RESPALDO_HORA (APP_TIMEZONE). Para respaldar ya:
//   php cron/respaldo_db.php --forzar
//
// .env:
//   RESPALDO_DIR   carpeta destino. Recomendado FUERA de public_html, p. ej.
//                  /home/USUARIO/respaldos/cliente (por defecto storage/backups,
//                  que el .htaccess bloquea por web)
//   RESPALDO_DIAS  cuántos respaldos conservar (por defecto 14)
//   RESPALDO_HORA  hora local del respaldo (por defecto 02:00)
// Los archivos subidos (uploads/, manuales/) no se incluyen: los cubre el
// respaldo de archivos de Hostinger.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/env.php';

function salir(string $mensaje, int $codigo = 0): void {
    fwrite($codigo ? STDERR : STDOUT, '[' . date('Y-m-d H:i:s') . "] $mensaje\n");
    exit($codigo);
}

$forzar = in_array('--forzar', $argv, true);
if (!$forzar && (int)date('G') !== (int)substr((string)env('RESPALDO_HORA', '02:00'), 0, 2)) {
    exit(0);
}

$dir = rtrim((string)env('RESPALDO_DIR', __DIR__ . '/../storage/backups'), '/\\');
$conservar = max(1, (int)env('RESPALDO_DIAS', 14));
if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
    salir("No se pudo crear la carpeta de respaldos: $dir", 1);
}
// Por si la carpeta queda dentro del sitio público: que nunca se sirva por web
if (!file_exists("$dir/.htaccess")) {
    @file_put_contents("$dir/.htaccess", "<IfModule mod_authz_core.c>
  Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
  Order allow,deny
  Deny from all
</IfModule>
");
}

$base = (string)env('DB_NAME', '');
$archivo = sprintf('%s/%s_%s.sql.gz', $dir, preg_replace('/[^A-Za-z0-9_-]/', '_', $base), date('Y-m-d_His'));

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', env('DB_HOST', 'localhost'), (int)env('DB_PORT', 3306), $base),
        (string)env('DB_USER', ''), (string)env('DB_PASS', ''),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false]
    );
    $pdo->exec("SET time_zone = '+00:00'");

    $gz = gzopen($archivo . '.tmp', 'wb6');
    gzwrite($gz, "-- Respaldo de `$base` generado por Servidesk el " . date('Y-m-d H:i:s') . "\n"
        . "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\nSET time_zone = '+00:00';\n\n");

    $tablas = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
    $filasTotales = 0;
    foreach ($tablas as $tabla) {
        $crear = $pdo->query("SHOW CREATE TABLE `$tabla`")->fetch(PDO::FETCH_NUM)[1];
        gzwrite($gz, "DROP TABLE IF EXISTS `$tabla`;\n$crear;\n\n");

        $stmt = $pdo->query("SELECT * FROM `$tabla`");
        $lote = [];
        while ($fila = $stmt->fetch(PDO::FETCH_NUM)) {
            $lote[] = '(' . implode(',', array_map(
                fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v), $fila
            )) . ')';
            if (count($lote) >= 200) {
                gzwrite($gz, "INSERT INTO `$tabla` VALUES\n" . implode(",\n", $lote) . ";\n");
                $filasTotales += count($lote);
                $lote = [];
            }
        }
        if ($lote) {
            gzwrite($gz, "INSERT INTO `$tabla` VALUES\n" . implode(",\n", $lote) . ";\n");
            $filasTotales += count($lote);
        }
        $stmt->closeCursor();
        gzwrite($gz, "\n");
    }
    gzwrite($gz, "SET FOREIGN_KEY_CHECKS = 1;\n");
    gzclose($gz);
    rename($archivo . '.tmp', $archivo);
} catch (Throwable $e) {
    if (isset($gz) && is_resource($gz)) gzclose($gz);
    @unlink($archivo . '.tmp');
    salir('Error al respaldar: ' . $e->getMessage(), 1);
}

// Rotación: conserva solo los más recientes de esta base
$existentes = glob(sprintf('%s/%s_*.sql.gz', $dir, preg_replace('/[^A-Za-z0-9_-]/', '_', $base))) ?: [];
rsort($existentes);
foreach (array_slice($existentes, $conservar) as $viejo) {
    @unlink($viejo);
}

salir(sprintf('Respaldo creado: %s (%d tablas, %d filas, %.1f KB). Conservados: %d',
    basename($archivo), count($tablas), $filasTotales, filesize($archivo) / 1024, min(count($existentes), $conservar)));
