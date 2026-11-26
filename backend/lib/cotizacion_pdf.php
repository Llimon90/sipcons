<?php
// PDF de una cotización (Dompdf, vendor/dompdf). Tamaño carta, con el logo y
// los colores de la empresa. NUNCA incluye costos ni márgenes: se arma desde
// los campos de precio de cada partida, no desde la respuesta de la interfaz.
// Requiere cotizaciones_helpers.php y config/branding.php.
require_once __DIR__ . '/../../vendor/dompdf/autoload.inc.php';
require_once __DIR__ . '/../../config/branding.php';

use Dompdf\Dompdf;
use Dompdf\Options;

function cotPdfDinero(float $n, string $moneda): string {
    return ($moneda === 'USD' ? 'US$' : '$') . number_format($n, 2);
}

function cotPdfCantidad($n): string {
    $n = (float)$n;
    return fmod($n, 1.0) == 0.0 ? number_format($n, 0) : rtrim(rtrim(number_format($n, 2), '0'), '.');
}

function cotPdfFecha(?string $f): string {
    if (!$f) return '';
    $meses = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
    $t = strtotime($f);
    return date('j', $t) . ' de ' . $meses[(int)date('n', $t) - 1] . ' de ' . date('Y', $t);
}

function cotPdfNombreArchivo(array $cot): string {
    $cliente = strtr($cot['cliente_nombre'], ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
        'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N']);
    $cliente = trim(preg_replace('/[^A-Za-z0-9]+/', '_', $cliente), '_') ?: 'Cliente';
    return $cot['folio_base'] . '_v' . (int)$cot['version'] . '_' . substr($cliente, 0, 40) . '.pdf';
}

/**
 * Logo propio de la empresa (subido en Mi empresa) como ruta local, o null.
 * La cotización es del cliente: sin logo propio se usa su nombre, nunca el
 * logo de Servidesk.
 */
function cotPdfLogo(array $empresa): ?string {
    $raiz = realpath(__DIR__ . '/../..');
    $logo = (string)($empresa['marca']['logo'] ?? '');
    if ($logo === '' || strpos($logo, 'uploads/e' . (int)($empresa['id'] ?? 0) . '/') !== 0) return null;
    $ruta = realpath($raiz . '/' . ltrim($logo, '/'));
    return $ruta && strpos($ruta, $raiz) === 0 && is_file($ruta) ? $ruta : null;
}

function cotPdfHtml(PDO $pdo, array $cot, array $partidas): string {
    $marca = brandConfig();
    $empresa = empresaActual() ?? [];
    $color = $marca['colores']['primario'] ?? '#3498db';
    $oscuro = $marca['colores']['sidebar'] ?? '#2c3e50';
    $e = fn($t) => htmlspecialchars((string)$t, ENT_QUOTES, 'UTF-8');
    $m = $cot['moneda'];
    $totales = cotCalcularTotales($partidas, (bool)$cot['precios_incluyen_iva']);

    $st = $pdo->prepare('SELECT rfc, direccion, telefono, email FROM clientes WHERE id = ?');
    $st->execute([(int)$cot['cliente_id']]);
    $cliente = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $contacto = [];
    if ($cot['contacto_id']) {
        $st = $pdo->prepare('SELECT nombre, telefono, email FROM cliente_contactos WHERE id = ?');
        $st->execute([(int)$cot['contacto_id']]);
        $contacto = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    $logo = cotPdfLogo($empresa);
    $logoHtml = $logo ? '<img src="' . $e($logo) . '" style="max-height:58px; max-width:210px;">' : '<div class="marca-texto">' . $e($empresa['nombre'] ?? $marca['empresa']) . '</div>';
    $aprobada = in_array($cot['estatus'], ['aprobada', 'convertida'], true);
    $datosEmpresa = array_filter([
        $empresa['razon_social'] ?? null ?: ($empresa['nombre'] ?? null),
        !empty($empresa['rfc']) ? 'RFC ' . $empresa['rfc'] : null,
        $empresa['contacto_telefono'] ?? null,
        $empresa['contacto_correo'] ?? null,
    ]);

    // Fotos de los productos del catálogo (Inventario, migración 008)
    $fotos = [];
    $raiz = realpath(__DIR__ . '/../..');
    $ids = array_values(array_unique(array_filter(array_map(fn($p) => (int)($p['articulo_id'] ?? 0), $partidas))));
    if ($ids && invTieneImagen($pdo)) {
        $st = $pdo->prepare('SELECT id, imagen FROM inventario_articulos WHERE imagen IS NOT NULL AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
        $st->execute($ids);
        foreach ($st->fetchAll(PDO::FETCH_KEY_PAIR) as $id => $img) {
            $ruta = realpath($raiz . '/' . ltrim((string)$img, '/'));
            if ($ruta && strpos($ruta, $raiz) === 0 && is_file($ruta)) $fotos[(int)$id] = $ruta;
        }
    }

    $filas = '';
    foreach ($totales['partidas'] as $i => $p) {
        $foto = $fotos[(int)($p['articulo_id'] ?? 0)] ?? null;
        $detalle = array_filter([$p['sku'] ?? null, trim(($p['marca'] ?? '') . ' ' . ($p['modelo'] ?? ''))]);
        $iva = $p['es_exento'] ? 'Exento' : ($p['tasa_iva'] > 0 ? 'IVA ' . ($p['tasa_iva'] * 100) . '%' : 'Tasa 0%');
        $noAceptada = $aprobada && isset($p['aceptada']) && (string)$p['aceptada'] === '0';
        $filas .= '<tr class="' . ($i % 2 ? 'par' : '') . ($noAceptada ? ' no-aceptada' : '') . '">'
            . '<td class="num">' . cotPdfCantidad($p['cantidad']) . '</td>'
            . '<td>' . $e($p['unidad']) . '</td>'
            . '<td>' . ($foto ? '<table class="con-foto"><tr><td class="foto"><img src="' . $e($foto) . '"></td><td>' : '')
            . '<strong>' . $e($p['descripcion']) . '</strong>'
            . ($detalle ? '<div class="sub">' . $e(implode(' · ', $detalle)) . ' · ' . $iva . '</div>' : '<div class="sub">' . $iva . '</div>')
            . (!empty($p['incluye']) ? '<div class="sub incluye">Incluye: ' . nl2br($e($p['incluye'])) . '</div>' : '')  // D-43
            . ($noAceptada ? '<div class="sub rechazo">No aceptada por el cliente</div>' : '')
            . ($foto ? '</td></tr></table>' : '') . '</td>'
            . '<td class="num">' . cotPdfDinero((float)$p['precio_unitario'], $m) . '</td>'
            . '<td class="num">' . ((float)$p['descuento_pct'] > 0 ? cotPdfCantidad($p['descuento_pct']) . '%' : '—') . '</td>'
            . '<td class="num">' . cotPdfDinero($p['importe'], $m) . '</td>'
            . '</tr>';
    }

    $filasTotales = '<tr><td>Subtotal</td><td class="num">' . cotPdfDinero($totales['subtotal'], $m) . '</td></tr>';
    if ($totales['descuento_total'] > 0) {
        $filasTotales .= '<tr><td>Descuento</td><td class="num">−' . cotPdfDinero($totales['descuento_total'], $m) . '</td></tr>';
    }
    foreach ($totales['iva_por_tasa'] as $t) {
        $filasTotales .= '<tr><td>' . $e($t['etiqueta']) . ($t['tasa'] > 0 ? '' : '') . '</td><td class="num">' . cotPdfDinero($t['iva'], $m) . '</td></tr>';
    }
    $filasTotales .= '<tr class="total"><td>Total ' . $m . '</td><td class="num">' . cotPdfDinero($totales['total'], $m) . '</td></tr>';
    if ($aprobada && (int)$cot['aprobacion_parcial'] === 1 && $cot['total_aprobado'] !== null) {
        $filasTotales .= '<tr class="aprobado"><td>Total aprobado</td><td class="num">' . cotPdfDinero((float)$cot['total_aprobado'], $m) . '</td></tr>';
    }

    // L-27: una versión sustituida no se puede reenviar como si siguiera vigente
    $marcaAgua = !empty($cot['reemplazada_por']) ? 'SUSTITUIDA'
        : (($cot['estatus'] === 'enviada' && $cot['vigencia_hasta'] < date('Y-m-d')) ? 'VENCIDA'  // H-02: aunque nadie la haya marcado
        : (['borrador' => 'BORRADOR', 'vencida' => 'VENCIDA', 'rechazada' => 'RECHAZADA'][$cot['estatus']] ?? ''));
    $parrafos = fn($t) => nl2br($e(trim((string)$t)));

    return '<!doctype html><html><head><meta charset="utf-8"><style>
        @page { margin: 32px 40px 56px 40px; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 9.5pt; color: #1e293b; }
        .cab { width: 100%; border-bottom: 3px solid ' . $color . '; padding-bottom: 10px; }
        .cab td { vertical-align: top; }
        .marca-texto { font-size: 16pt; font-weight: bold; color: ' . $oscuro . '; }
        .empresa { font-size: 8pt; color: #64748b; line-height: 1.45; margin-top: 4px; }
        .doc { text-align: right; }
        .doc .tipo { font-size: 17pt; font-weight: bold; color: ' . $oscuro . '; letter-spacing: 1px; }
        .doc .folio { font-size: 12pt; font-weight: bold; color: ' . $color . '; }
        .doc .fechas { font-size: 8.5pt; color: #475569; margin-top: 4px; line-height: 1.5; }
        .bloques { width: 100%; margin: 14px 0 12px; }
        .bloque { background: #f1f5f9; border-radius: 6px; padding: 9px 12px; vertical-align: top; }
        .bloque h4 { margin: 0 0 4px; font-size: 7.5pt; text-transform: uppercase; letter-spacing: 1px; color: ' . $color . '; }
        .bloque .nombre { font-size: 10.5pt; font-weight: bold; }
        .bloque .dato { font-size: 8.5pt; color: #475569; }
        table.partidas { width: 100%; border-collapse: collapse; }
        table.partidas th { background: ' . $oscuro . '; color: #fff; font-size: 8pt; text-transform: uppercase; padding: 6px 7px; text-align: left; }
        table.partidas td { padding: 6px 7px; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
        table.partidas tr.par td { background: #f8fafc; }
        .num { text-align: right; white-space: nowrap; }
        .sub { font-size: 7.5pt; color: #64748b; margin-top: 2px; }
        table.con-foto { border-collapse: collapse; }
        table.con-foto td { padding: 0; border: 0; background: none !important; vertical-align: top; }
        table.con-foto td.foto { width: 54px; padding-right: 8px; }
        table.con-foto img { width: 46px; border: 1px solid #e2e8f0; border-radius: 4px; }
        table.totales { width: 46%; margin-left: 54%; border-collapse: collapse; margin-top: 8px; }
        table.totales td { padding: 4px 7px; }
        table.totales tr.total td { background: ' . $color . '; color: #fff; font-weight: bold; font-size: 11pt; }
        table.totales tr.aprobado td { font-weight: bold; color: ' . $oscuro . '; border-bottom: 2px solid ' . $color . '; }
        tr.no-aceptada td { color: #94a3b8; }
        .rechazo { color: #b45309; font-weight: bold; }
        .tc { font-size: 8pt; color: #64748b; text-align: right; margin-top: 4px; }
        h3 { font-size: 9pt; text-transform: uppercase; letter-spacing: 1px; color: ' . $color . '; margin: 16px 0 4px; }
        .texto { font-size: 8.5pt; color: #334155; line-height: 1.5; }
        .firma { margin-top: 46px; width: 100%; }
        .firma td { width: 50%; text-align: center; font-size: 8.5pt; color: #475569; vertical-align: top; }
        .linea { border-top: 1px solid #94a3b8; margin: 0 30px; padding-top: 5px; }
        .agua { position: fixed; top: 38%; left: 0; width: 100%; text-align: center; font-size: 80pt; color: rgba(148,163,184,0.18); transform: rotate(-30deg); font-weight: bold; }
    </style></head><body>'
    . ($marcaAgua ? '<div class="agua">' . $marcaAgua . '</div>' : '')
    . '<table class="cab"><tr><td>' . $logoHtml . '<div class="empresa">' . implode('<br>', array_map($e, $datosEmpresa)) . '</div></td>'
    . '<td class="doc"><div class="tipo">COTIZACIÓN</div><div class="folio">' . $e($cot['folio']) . '</div>'
    . '<div class="fechas">Fecha: ' . cotPdfFecha($cot['fecha']) . '<br>Vigencia: hasta el ' . cotPdfFecha($cot['vigencia_hasta'])
    . ((int)$cot['version'] > 1 ? '<br>Versión ' . (int)$cot['version'] : '') . '</div></td></tr></table>'
    . '<table class="bloques" cellspacing="0"><tr><td class="bloque" style="width:58%">'
    . '<h4>Cliente</h4><div class="nombre">' . $e($cot['cliente_nombre']) . '</div>'
    . (!empty($cliente['rfc']) ? '<div class="dato">RFC ' . $e($cliente['rfc']) . '</div>' : '')
    . ($cot['sucursal'] ? '<div class="dato">Sucursal: ' . $e($cot['sucursal']) . '</div>' : '')
    . (!empty($cliente['direccion']) ? '<div class="dato">' . $e($cliente['direccion']) . '</div>' : '')
    . '</td><td style="width:2%"></td><td class="bloque" style="width:40%"><h4>Atención</h4>'
    . '<div class="nombre">' . $e($cot['contacto_nombre'] ?: '—') . '</div>'
    . (!empty($contacto['telefono']) ? '<div class="dato">' . $e($contacto['telefono']) . '</div>' : '')
    . (!empty($contacto['email']) ? '<div class="dato">' . $e($contacto['email']) . '</div>' : '')
    . '</td></tr></table>'
    . '<table class="partidas"><thead><tr><th class="num" style="width:7%">Cant.</th><th style="width:8%">Unidad</th><th>Descripción</th>'
    . '<th class="num" style="width:14%">P. unitario</th><th class="num" style="width:8%">Desc.</th><th class="num" style="width:14%">Importe</th></tr></thead>'
    . '<tbody>' . $filas . '</tbody></table>'
    . '<table class="totales">' . $filasTotales . '</table>'
    . ($cot['precios_incluyen_iva'] ? '<div class="tc">Los precios unitarios incluyen IVA.</div>' : '')
    . ($m === 'USD' ? '<div class="tc">Importes en dólares estadounidenses. Tipo de cambio de referencia: $' . number_format((float)$cot['tipo_cambio'], 4) . ' MXN por dólar.</div>' : '')
    . (trim((string)$cot['condiciones']) !== '' ? '<h3>Condiciones</h3><div class="texto">' . $parrafos($cot['condiciones']) . '</div>' : '')
    . (trim((string)$cot['notas_cliente']) !== '' ? '<h3>Notas</h3><div class="texto">' . $parrafos($cot['notas_cliente']) . '</div>' : '')
    . '<table class="firma"><tr><td><div class="linea">' . $e($cot['creado_por_nombre'] ?: ($empresa['nombre'] ?? '')) . '<br>Elaboró</div></td>'
    . '<td><div class="linea">&nbsp;<br>Nombre y firma de aceptación</div></td></tr></table>'
    . '</body></html>';
}

/** Genera el PDF y lo envía como descarga, o en línea para la vista previa (D-47). */
function cotEnviarPdf(PDO $pdo, int $id, bool $enLinea = false): void {
    $cot = cotFila($pdo, $id);
    $partidas = cotPartidas($pdo, $id);
    $html = cotPdfHtml($pdo, $cot, $partidas);

    $opciones = new Options();
    $opciones->set('defaultFont', 'DejaVu Sans');
    $opciones->set('isRemoteEnabled', false);
    $opciones->set('chroot', realpath(__DIR__ . '/../..'));
    $opciones->set('tempDir', sys_get_temp_dir());
    $dompdf = new Dompdf($opciones);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('letter', 'portrait');
    $dompdf->render();

    // Pie: folio y número de página
    $canvas = $dompdf->getCanvas();
    $fuente = $dompdf->getFontMetrics()->getFont('DejaVu Sans');
    $canvas->page_text(40, $canvas->get_height() - 34, $cot['folio'] . ' · ' . $cot['cliente_nombre'], $fuente, 7.5, [0.45, 0.5, 0.58]);
    $canvas->page_text($canvas->get_width() - 110, $canvas->get_height() - 34, 'Página {PAGE_NUM} de {PAGE_COUNT}', $fuente, 7.5, [0.45, 0.5, 0.58]);

    $nombre = cotPdfNombreArchivo($cot);
    header('Content-Type: application/pdf');
    header('Content-Disposition: ' . ($enLinea ? 'inline' : 'attachment') . '; filename="' . $nombre . '"');
    header('Cache-Control: private, no-store');
    echo $dompdf->output();
}
