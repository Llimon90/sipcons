<?php
/**
 * Bitácora de inicios de sesión.
 *
 * Escribe una línea por evento en storage/logs/login-AAAA-MM.log (un archivo
 * por mes). No se expone en el portal: solo se consulta desde el
 * Administrador de archivos de cPanel. Nunca registra contraseñas.
 * Un fallo al escribir el log jamás debe romper el login.
 */
function logLoginEvent(string $evento, string $usuario, string $detalle = ''): void {
    try {
        $dir = __DIR__ . '/../storage/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }

        // Quita saltos de línea/tabuladores para evitar inyección de líneas falsas.
        $limpia = static function (string $s, int $max): string {
            return mb_substr(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $s), 0, $max);
        };

        $linea = implode("\t", [
            date('Y-m-d H:i:s'),
            $evento,
            $limpia($usuario, 60),
            $_SERVER['REMOTE_ADDR'] ?? '-',
            $limpia($_SERVER['HTTP_USER_AGENT'] ?? '-', 150),
            $limpia($detalle, 100),
        ]) . PHP_EOL;

        @file_put_contents($dir . '/login-' . date('Y-m') . '.log', $linea, FILE_APPEND | LOCK_EX);
    } catch (Throwable $e) {
        // Silencioso a propósito.
    }
}
