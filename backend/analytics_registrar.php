<?php
// Recibe lotes de eventos de uso desde scripts/analytics.js.
// La identidad (usuario, rol) SIEMPRE sale de la sesión del servidor, nunca
// del cliente. Nunca debe romper el sitio: ante cualquier problema responde
// rápido y en silencio.
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/lib/analytics_helpers.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit;
}
if (!isLoggedIn()) {
    http_response_code(401);
    exit;
}

$raw = file_get_contents('php://input', false, null, 0, 65537);
if ($raw === false || strlen($raw) > 65536) {
    http_response_code(413);
    exit;
}
$lote = json_decode($raw, true);
if (!is_array($lote) || empty($lote['ev']) || !is_array($lote['ev'])) {
    http_response_code(204);
    exit;
}

$idSeguro = fn($v) => is_string($v) && preg_match('/^[A-Za-z0-9_-]{6,40}$/', $v) ? $v : null;
$sesion = $idSeguro($lote['s'] ?? null);
$visita = $idSeguro($lote['p'] ?? null);
if ($sesion === null || $visita === null) {
    http_response_code(204);
    exit;
}

$paginaLimpia = function ($v) {
    $v = is_string($v) ? basename($v) : '';
    return preg_match('/^[A-Za-z0-9_.-]{1,100}$/', $v) ? $v : null;
};
$pagina = $paginaLimpia($lote['pg'] ?? null);
$referrer = $paginaLimpia($lote['ref'] ?? null);
$viewport = is_string($lote['vp'] ?? null) && preg_match('/^\d{2,5}x\d{2,5}$/', $lote['vp']) ? $lote['vp'] : null;
$ua = analyticsParseUA($_SERVER['HTTP_USER_AGENT'] ?? '');
$modulo = $pagina !== null ? analyticsInfoPagina($pagina)['modulo'] : null;

$usuarioId = $_SESSION['user_id'] ?? null;
$usuario = analyticsLimpiar($_SESSION['usuario'] ?? null, 100);
$usuarioNombre = analyticsLimpiar($_SESSION['nombre'] ?? null, 150);
$rol = analyticsLimpiar($_SESSION['rol'] ?? null, 60);

$clamp = fn($v) => is_numeric($v) ? (int)max(-2147483648, min(2147483647, $v)) : null;

$filas = [];
foreach (array_slice($lote['ev'], 0, 50) as $e) {
    if (!is_array($e) || !in_array($e['t'] ?? null, ANALYTICS_TIPOS, true)) continue;
    $filas[] = [
        $usuarioId, $usuario, $usuarioNombre, $rol, $sesion, $visita, $e['t'], $pagina, $modulo,
        analyticsLimpiar($e['e'] ?? null, 200), analyticsLimpiar($e['d'] ?? null, 300),
        $clamp($e['v'] ?? null), $clamp($e['x'] ?? null),
        $referrer, $ua['dispositivo'], $ua['navegador'], $ua['sistema'], $viewport,
    ];
}
if (empty($filas)) {
    http_response_code(204);
    exit;
}

try {
    // Tope por usuario para que un bug de cliente no llene la base.
    $desde = date('Y-m-d H:i:s', time() - 60);
    $st = $pdo->prepare('SELECT COUNT(*) FROM analytics_eventos WHERE usuario_id = ? AND creado_en >= ?');
    $st->execute([$usuarioId, $desde]);
    if ((int)$st->fetchColumn() >= 1500) {
        http_response_code(429);
        exit;
    }

    $cols = 'usuario_id, usuario, usuario_nombre, rol, sesion_id, visita_id, tipo, pagina, modulo, elemento, detalle, valor, extra, referrer_pagina, dispositivo, navegador, sistema, viewport';
    $unaFila = '(' . implode(',', array_fill(0, 18, '?')) . ')';
    $sql = "INSERT INTO analytics_eventos ({$cols}) VALUES " . implode(',', array_fill(0, count($filas), $unaFila));
    $params = [];
    foreach ($filas as $fila) {
        foreach ($fila as $v) $params[] = $v;
    }
    $pdo->prepare($sql)->execute($params);

    // Limpieza ocasional: se conservan 2 años de eventos.
    if (mt_rand(1, 300) === 1) {
        $pdo->prepare('DELETE FROM analytics_eventos WHERE creado_en < ?')->execute([date('Y-m-d H:i:s', time() - 730 * 86400)]);
    }
    http_response_code(204);
} catch (\Throwable $e) {
    error_log('Analíticas: no se pudo registrar el lote (¿falta la migración 011?): ' . $e->getMessage());
    http_response_code(204);
}
