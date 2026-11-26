<?php
// D-54: documento compartido por enlace de solo lectura (cotización en PDF o estado de cuenta).
// Público a propósito (quien recibe el enlace no tiene cuenta): el acceso lo da la firma del
// enlace (config/enlaces.php), que caduca. No abre nada más que ese documento.
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/plataforma.php';
require_once __DIR__ . '/../config/enlaces.php';

http_response_code(200);
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: private, no-store');
header('Referrer-Policy: no-referrer');

function compartidoAviso(int $http, string $titulo, string $texto): void {
    http_response_code($http);
    header('Content-Type: text/html; charset=utf-8');
    $e = fn($s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    echo '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . $e($titulo) . '</title><style>body{font-family:system-ui,sans-serif;background:#f5f7fa;color:#1e293b;margin:0;padding:48px 16px}'
        . 'main{max-width:480px;margin:auto;background:#fff;border-radius:12px;padding:24px;border:1px solid #e2e8f0}h1{font-size:1.25rem;margin:0 0 8px}</style></head>'
        . '<body><main><h1>' . $e($titulo) . '</h1><p>' . $e($texto) . '</p></main></body></html>';
    exit;
}

$enlace = enlaceVerificar($pdo, (string)($_GET['t'] ?? ''));
if (!$enlace) compartidoAviso(404, 'Enlace no válido', 'Este enlace no es válido o ya caducó. Pide a quien te lo envió uno nuevo.');

$empresa = empresaPorId($pdo, $enlace['e']);
if (!$empresa || situacionEmpresa($empresa)['acceso'] === 'bloqueado') {
    compartidoAviso(404, 'Documento no disponible', 'Este documento ya no está disponible. Ponte en contacto con quien te lo envió.');
}
establecerEmpresa($enlace['e']);

try {
    if ($enlace['t'] === 'cotizacion') {
        require_once __DIR__ . '/lib/cotizaciones_helpers.php';
        require_once __DIR__ . '/lib/cotizacion_pdf.php';
        $cot = cotFila($pdo, $enlace['i']);
        if (!$cot || $cot['estatus'] === 'borrador') compartidoAviso(404, 'Documento no disponible', 'Esta cotización ya no está disponible.');
        cotEnviarPdf($pdo, $enlace['i'], true);
        exit;
    }

    // Estado de cuenta: solo saldos y movimientos del cliente, en una página para leer o imprimir
    require_once __DIR__ . '/lib/finanzas_helpers.php';
    $ec = finEstadoCuenta($pdo, $enlace['i']);
    $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $din = fn($n) => '$' . number_format((float)$n, 2);
    $fecha = fn($f) => $f ? date('d/m/Y', strtotime($f)) : '';
    header('Content-Type: text/html; charset=utf-8');
    $filas = '';
    foreach ($ec['cuentas'] as $c) {
        $filas .= '<tr><td>' . $e($c['folio']) . '</td><td>' . $e($c['concepto']) . ($c['factura'] ? '<br><small>Factura ' . $e($c['factura']) . '</small>' : '')
            . '</td><td>' . $fecha($c['fecha']) . '</td><td>' . $fecha($c['vencimiento']) . ($c['dias_vencida'] > 0 ? '<br><small class="v">' . (int)$c['dias_vencida'] . ' días vencida</small>' : '')
            . '</td><td class="n">' . $din($c['total']) . '</td><td class="n"><b>' . $din($c['saldo']) . '</b></td></tr>';
    }
    $cobros = '';
    foreach ($ec['cobros'] as $b) {
        $cobros .= '<tr><td>' . $fecha($b['fecha']) . '</td><td>' . $e($b['folio']) . '</td><td>' . $e($b['referencia']) . '</td><td class="n">' . $din($b['monto']) . '</td></tr>';
    }
    echo '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>Estado de cuenta · ' . $e($ec['cliente']['nombre']) . '</title><style>'
        . 'body{font-family:system-ui,sans-serif;color:#1e293b;margin:0;padding:24px 16px;background:#f5f7fa}main{max-width:880px;margin:auto;background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:24px}'
        . 'h1{font-size:1.25rem;margin:0}h2{font-size:1rem;margin:24px 0 8px}p{margin:4px 0}table{width:100%;border-collapse:collapse;font-size:.9rem}th,td{padding:8px;border-bottom:1px solid #e2e8f0;text-align:left;vertical-align:top}'
        . '.n{text-align:right;white-space:nowrap}.v{color:#b91c1c}.saldo{font-size:1.1rem;margin-top:12px}.tabla{overflow-x:auto}@media print{body{background:#fff;padding:0}main{border:0}}</style></head><body><main>'
        . '<h1>Estado de cuenta</h1><p>' . $e($empresa['nombre']) . ' · al ' . $fecha($ec['fecha']) . '</p><p><b>' . $e($ec['cliente']['nombre']) . '</b></p>'
        . '<p class="saldo">Saldo: <b>' . $din($ec['saldo']) . '</b>' . ($ec['vencido'] > 0 ? ' · vencido: <b class="v">' . $din($ec['vencido']) . '</b>' : '') . '</p>'
        . '<h2>Cuentas pendientes</h2><div class="tabla"><table><thead><tr><th>Folio</th><th>Concepto</th><th>Fecha</th><th>Vence</th><th class="n">Total</th><th class="n">Saldo</th></tr></thead><tbody>'
        . ($filas ?: '<tr><td colspan="6">Sin cuentas pendientes.</td></tr>') . '</tbody></table></div>'
        . '<h2>Pagos recibidos (últimos 90 días)</h2><div class="tabla"><table><thead><tr><th>Fecha</th><th>Cuenta</th><th>Referencia</th><th class="n">Monto</th></tr></thead><tbody>'
        . ($cobros ?: '<tr><td colspan="4">Sin pagos en los últimos 90 días.</td></tr>') . '</tbody></table></div>'
        . '<p style="margin-top:24px;color:#64748b;font-size:.8rem">Documento de solo lectura. El enlace caduca el ' . date('d/m/Y', $enlace['x']) . '.</p>'
        . '</main></body></html>';
} catch (Throwable $ex) {
    error_log('Documento compartido: ' . $ex->getMessage());
    compartidoAviso(404, 'Documento no disponible', 'No se pudo abrir este documento. Pide a quien te lo envió uno nuevo.');
}
