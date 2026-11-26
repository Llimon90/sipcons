<?php
// Carga el archivo .env de la raíz una sola vez. Separado de database.php
// para que la configuración de marca (config/branding.php) pueda leerse sin
// abrir una conexión a la base de datos.
if (!function_exists('loadEnv')):
function loadEnv(string $path): void {
    if (!file_exists($path)) return;
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        // Permite valores entre comillas: APP_NAME="Mi Empresa"
        if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && substr($value, -1) === $value[0]) {
            $value = substr($value, 1, -1);
        }
        if ($key !== '') {
            $_ENV[$key] = $value;
            putenv("$key=$value");
        }
    }
}
endif;

function env(string $key, $default = null) {
    $value = $_ENV[$key] ?? getenv($key);
    return ($value === false || $value === null || $value === '') ? $default : $value;
}

if (!defined('APP_ENV_LOADED')) {
    define('APP_ENV_LOADED', true);
    loadEnv(__DIR__ . '/../.env');
    // Zona horaria de la operación del cliente
    $tz = env('APP_TIMEZONE', 'America/Mexico_City');
    if (!@date_default_timezone_set($tz)) date_default_timezone_set('America/Mexico_City');
}
