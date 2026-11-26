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

// ------------------------------------------------------------------
// Bloqueo temporal por intentos fallidos (tabla login_intentos, migración 008).
// 5 fallos con el mismo correo, o 30 desde la misma IP, en 15 minutos bloquean
// ese acceso hasta que pasen 15 minutos desde el primero de esos fallos. Sin la
// migración no se bloquea nada (el login funciona igual).
const LOGIN_MAX_FALLOS_USUARIO = 5;
const LOGIN_MAX_FALLOS_IP = 30;
const LOGIN_MAX_FALLOS_USUARIO_GLOBAL = 50;  // S-6
const LOGIN_VENTANA_MINUTOS = 15;

/** Minutos que faltan para poder intentar de nuevo, o 0 si no hay bloqueo. */
function loginMinutosBloqueo(PDO $pdo, string $usuario): int {
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $ventana = 'creado_en >= NOW() - INTERVAL ' . LOGIN_VENTANA_MINUTOS . ' MINUTE';
        $peor = 0;
        // S-6: el bloqueo por correo cuenta solo los fallos desde esa IP (nadie bloquea la cuenta de
        // otro desde fuera); un tope global por correo, más alto, frena ataques repartidos entre IPs
        $porCorreo = $ip !== '' ? ['usuario = ? AND ip = ?', [$usuario, $ip], LOGIN_MAX_FALLOS_USUARIO]
                                 : ['usuario = ?', [$usuario], LOGIN_MAX_FALLOS_USUARIO];  // IP desconocida: regla estricta
        foreach ([$porCorreo,
                  ['usuario = ?', [$usuario], LOGIN_MAX_FALLOS_USUARIO_GLOBAL],
                  ['ip = ?', [$ip], LOGIN_MAX_FALLOS_IP]] as [$cond, $valores, $max]) {
            if (in_array('', $valores, true)) continue;
            $st = $pdo->prepare("SELECT COUNT(*) AS n, TIMESTAMPDIFF(SECOND, NOW(), MIN(creado_en) + INTERVAL " . LOGIN_VENTANA_MINUTOS . " MINUTE) AS resta
                                 FROM login_intentos WHERE $cond AND $ventana");
            $st->execute($valores);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if ((int)$r['n'] >= $max) $peor = max($peor, (int)ceil(max(60, (int)$r['resta']) / 60));
        }
        return $peor;
    } catch (Throwable $e) {
        return 0;
    }
}

function loginRegistrarFallo(PDO $pdo, string $usuario): void {
    try {
        $pdo->prepare('INSERT INTO login_intentos (usuario, ip) VALUES (?, ?)')->execute([mb_substr($usuario, 0, 190), $_SERVER['REMOTE_ADDR'] ?? null]);
        // Limpieza: lo de más de un día ya no sirve
        if (random_int(1, 20) === 1) $pdo->exec('DELETE FROM login_intentos WHERE creado_en < NOW() - INTERVAL 1 DAY');
    } catch (Throwable $e) {
        // Sin la migración 008: sin bloqueo
    }
}

function loginLimpiarFallos(PDO $pdo, string $usuario): void {
    try {
        $pdo->prepare('DELETE FROM login_intentos WHERE usuario = ?')->execute([$usuario]);
    } catch (Throwable $e) {
    }
}
