<?php
// Carga del .env, separada de config/database.php para que un endpoint pueda
// leer la configuración (p. ej. ANALYTICS_ENABLED) SIN abrir conexiones a
// MySQL ni la sesión cuando no las necesita.
if (!function_exists('loadEnv')) {
    function loadEnv(string $path): void {
        if (!file_exists($path)) return;
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            if ($key !== '') {
                $_ENV[$key] = $value;
                putenv("$key=$value");
            }
        }
    }
}

// Interruptor booleano del .env: "0", "false", "no", "off" (sin importar
// mayúsculas) lo apagan; si la clave no existe se usa $default.
if (!function_exists('envBool')) {
    function envBool(string $clave, bool $default): bool {
        if (!isset($_ENV[$clave])) return $default;
        return !in_array(strtolower(trim((string)$_ENV[$clave])), ['0', 'false', 'no', 'off', ''], true);
    }
}

loadEnv(__DIR__ . '/../.env');
