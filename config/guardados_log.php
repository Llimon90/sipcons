<?php
/**
 * Bitácora de guardados que NO se completaron.
 *
 * Una línea por intento fallido en storage/logs/guardados-AAAA-MM.log (un
 * archivo por mes, separado por tabuladores). Sirve para responder "guardé y
 * no aparece": quién, cuándo, qué registro y por qué no se guardó (sesión
 * cerrada, archivos muy pesados, otra persona lo modificó, error del
 * servidor...). No se expone en el portal: se consulta desde el
 * Administrador de archivos de cPanel. Nunca guarda el texto capturado.
 * Un fallo al escribir el log jamás debe romper la petición.
 *
 * Columnas: fecha · motivo · usuario · rol · registro · página/endpoint ·
 *           tamaño del envío (bytes) · IP · navegador · detalle
 */
function registrarFalloGuardado(string $motivo, ?string $registro = null, string $detalle = ''): void {
    try {
        $dir = __DIR__ . '/../storage/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }

        $limpia = static function ($s, int $max): string {
            return mb_substr(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string)$s), 0, $max);
        };

        $linea = implode("\t", [
            date('Y-m-d H:i:s'),
            $limpia($motivo, 40),
            $limpia($_SESSION['usuario'] ?? '(sin sesión)', 60),
            $limpia($_SESSION['rol'] ?? '-', 40),
            $limpia($registro ?? '-', 40),
            $limpia(basename($_SERVER['SCRIPT_NAME'] ?? '-'), 60),
            (int)($_SERVER['CONTENT_LENGTH'] ?? 0),
            $_SERVER['REMOTE_ADDR'] ?? '-',
            $limpia($_SERVER['HTTP_USER_AGENT'] ?? '-', 150),
            $limpia($detalle, 400),
        ]) . PHP_EOL;

        if (@file_put_contents($dir . '/guardados-' . date('Y-m') . '.log', $linea, FILE_APPEND | LOCK_EX) === false) {
            error_log('Guardado fallido (no se pudo escribir la bitácora): ' . trim($linea));
        }
    } catch (Throwable $e) {
        // Silencioso a propósito.
    }
}
