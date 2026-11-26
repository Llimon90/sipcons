<?php
// Corre las pruebas de tests/*_test.php (ver tests/lib.php).
require_once __DIR__ . '/lib.php';

$filtro = $argv[1] ?? '';
$archivos = array_filter(glob(__DIR__ . '/*_test.php'), fn($f) => $filtro === '' || stripos(basename($f), $filtro) !== false);
register_shutdown_function('limpiarEmpresasTemporales');

foreach ($archivos as $archivo) {
    echo "\n== " . basename($archivo) . " ==";
    require $archivo;
}
limpiarEmpresasTemporales();

$r = $GLOBALS['__pruebas'];
$total = $r['ok'] + count($r['fallas']);
echo "\n" . ($r['fallas'] ? "\033[31m" : "\033[32m") . "{$r['ok']} de $total pruebas correctas\033[0m\n";
if ($r['fallas']) {
    echo "Fallaron:\n  - " . implode("\n  - ", $r['fallas']) . "\n";
    exit(1);
}
