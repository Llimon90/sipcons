<?php
// D-54 (2026-11-24): enlaces de SOLO LECTURA que caducan, para compartir por WhatsApp una
// cotización o un estado de cuenta. No es un portal (D-21) ni una notificación (D-19): lo
// manda la persona, a mano, y el enlace solo abre ese documento.
//
// El enlace lleva tipo, empresa, id y caducidad, firmados con HMAC-SHA256 y una llave por
// instancia (plataforma_config.enlaces_secreto, se genera sola). Cambiar esa llave invalida
// todos los enlaces que se hayan compartido. Requiere $pdo.

const ENLACE_TIPOS = ['cotizacion', 'estado_cuenta'];
const ENLACE_DIAS = 7;

function enlaceSecreto(PDO $pdo): string {
    static $secreto = null;
    if ($secreto !== null) return $secreto;
    $leer = function () use ($pdo) {
        $st = $pdo->prepare("SELECT valor FROM plataforma_config WHERE clave = 'enlaces_secreto'");
        $st->execute();
        return (string)$st->fetchColumn();
    };
    $s = $leer();
    if (strlen($s) < 32) {
        // INSERT IGNORE: si dos peticiones lo crean a la vez, gana la primera y ambas leen la misma
        $pdo->prepare("INSERT IGNORE INTO plataforma_config (clave, valor, actualizado_en) VALUES ('enlaces_secreto', ?, NOW())")
            ->execute([bin2hex(random_bytes(32))]);
        $s = $leer();
    }
    if (strlen($s) < 32) throw new RuntimeException('No se pudo preparar la llave de los enlaces.');
    return $secreto = $s;
}

function enlaceB64(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function enlaceB64d(string $s): string|false { return base64_decode(strtr($s, '-_', '+/'), true); }

/** Token firmado para un documento de la empresa. */
function enlaceFirmar(PDO $pdo, string $tipo, int $empresaId, int $id, ?int $caduca = null): string {
    if (!in_array($tipo, ENLACE_TIPOS, true) || $empresaId <= 0 || $id <= 0) throw new InvalidArgumentException('Documento no válido para compartir.');
    $datos = enlaceB64(json_encode(['t' => $tipo, 'e' => $empresaId, 'i' => $id, 'x' => $caduca ?? time() + ENLACE_DIAS * 86400]));
    return $datos . '.' . enlaceB64(hash_hmac('sha256', $datos, enlaceSecreto($pdo), true));
}

/** ['t' => tipo, 'e' => empresa, 'i' => id, 'x' => caducidad] o null si el token no sirve o ya caducó. */
function enlaceVerificar(PDO $pdo, string $token): ?array {
    if (!preg_match('/^([A-Za-z0-9_-]{10,400})\.([A-Za-z0-9_-]{40,60})$/', $token, $m)) return null;
    $esperada = enlaceB64(hash_hmac('sha256', $m[1], enlaceSecreto($pdo), true));
    if (!hash_equals($esperada, $m[2])) return null;
    $json = enlaceB64d($m[1]);
    $p = $json !== false ? json_decode($json, true) : null;
    if (!is_array($p) || !in_array($p['t'] ?? null, ENLACE_TIPOS, true) || (int)($p['e'] ?? 0) <= 0 || (int)($p['i'] ?? 0) <= 0) return null;
    if ((int)($p['x'] ?? 0) < time()) return null;
    return ['t' => $p['t'], 'e' => (int)$p['e'], 'i' => (int)$p['i'], 'x' => (int)$p['x']];
}

/** URL pública del enlace (relativa si no hay APP_URL). */
function enlaceUrl(string $token): string {
    $base = rtrim((string)env('APP_URL', ''), '/');
    return ($base !== '' ? $base : '') . '/backend/compartido.php?t=' . rawurlencode($token);
}

/** Mensaje y enlace de WhatsApp (wa.me) para que la persona lo envíe. */
function enlaceWhatsapp(string $texto, ?string $telefono = null): string {
    $tel = preg_replace('/\D+/', '', (string)$telefono);
    if (strlen($tel) === 10) $tel = '52' . $tel;  // México
    return 'https://wa.me/' . (strlen($tel) >= 11 ? $tel : '') . '?text=' . rawurlencode($texto);
}
