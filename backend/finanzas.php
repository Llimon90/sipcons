<?php
// API del módulo de finanzas. Un solo endpoint con ?accion=...
//
//   GET  resumen                      tablero: por cobrar, vencido, cobrado y gastos del mes, antigüedad, deudores
//   GET  cuentas                      filtros: buscar, estado (abiertas|vencidas|pagadas|canceladas|todas), cliente_id, desde, hasta
//   GET  cuenta&id=                   con sus cobros
//   GET  pendientes                   cotizaciones aprobadas y órdenes cerradas con factura sin cuenta
//   GET  origen&tipo=&id=             datos para crear la cuenta de una cotización, orden o venta
//   GET  buscar_origen&q=             órdenes de servicio y ventas por folio o cliente (solo_ordenes=1 para gastos)
//   GET  clientes                     catálogo ligero (id, nombre, días de crédito)
//   GET  gastos                       filtros: desde, hasta, categoria, buscar, incluir_cancelados
//   GET  rentabilidad&desde=&hasta=   por orden de servicio y por cliente
//   GET  estado_cuenta&cliente_id=
//   GET  exportar&tipo=cuentas|cobros|gastos&desde=&hasta=   CSV para el contador
//   POST crear_cuenta | editar_cuenta | cancelar_cuenta
//   POST cobro                        (multipart: datos JSON + comprobante opcional)
//   POST comprobante_cobro            (multipart: datos JSON {id} + comprobante) adjunta o reemplaza (O-1)
//   POST cancelar_cobro               (también cancela notas de crédito)
//   POST guardar_credito              JSON: cliente_id, dias_credito, limite_credito (D-48)
//   POST nota_credito                 JSON: cuenta_id, monto, motivo, referencia (folio o UUID), fecha (D-48)
//   POST guardar_gasto                (multipart: datos JSON + comprobante opcional)
//   POST cancelar_gasto
//
// Permisos: módulo "finanzas". Cancelar (cuentas, cobros, gastos) solo el
// Administrador. El modo solo lectura de la cuenta lo aplica requireAuth().
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../auth/permisos.php';
require_once __DIR__ . '/lib/finanzas_helpers.php';
require_once __DIR__ . '/../config/csv.php';

$accion = $_GET['accion'] ?? '';
$metodo = $_SERVER['REQUEST_METHOD'];
if ($metodo === 'POST') {
    $body = isset($_POST['datos']) ? (json_decode((string)$_POST['datos'], true) ?: []) : (json_decode(file_get_contents('php://input'), true) ?: []);
} else {
    $body = [];
}

if ($accion !== 'exportar') header('Content-Type: application/json');

function responder(array $datos, int $codigo = 200): void {
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

/** CSV con BOM para que Excel respete los acentos. */
function enviarCsv(string $nombre, array $encabezados, array $filas): void {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $nombre . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $encabezados);
    foreach ($filas as $f) fputcsv($out, csvFilaSegura($f));  // S-5
    fclose($out);
    exit;
}

if (!tienePermiso('finanzas')) {
    responder(['exito' => false, 'mensaje' => 'Tu rol no tiene acceso a Finanzas.'], 403);
}
if (!finDisponible($pdo)) {
    responder(['exito' => false, 'mensaje' => 'El módulo de finanzas no está instalado en la base de datos (migración 009).'], 500);
}

$comprobante = null;
try {
    switch ("$metodo:$accion") {

    case 'GET:resumen':
        responder(['exito' => true, 'resumen' => finResumen($pdo)]);

    case 'GET:cuentas':
        responder(['exito' => true, 'cuentas' => finListarCuentas($pdo, $_GET)]);

    case 'GET:cuenta':
        responder(['exito' => true, 'cuenta' => finCuenta($pdo, (int)($_GET['id'] ?? 0))]);

    case 'GET:pendientes':
        responder(['exito' => true, 'pendientes' => finPendientesDeRegistrar($pdo, 200)]);

    case 'GET:origen':
        responder(['exito' => true, 'origen' => finOrigen($pdo, (string)($_GET['tipo'] ?? ''), (int)($_GET['id'] ?? 0))]);

    case 'GET:buscar_origen':
        responder(['exito' => true, 'resultados' => finBuscarOrigen($pdo, (string)($_GET['q'] ?? ''), !empty($_GET['solo_ordenes']))]);

    case 'GET:clientes':
        $st = $pdo->query('SELECT id, nombre, dias_credito FROM clientes ORDER BY nombre');
        responder(['exito' => true, 'clientes' => $st->fetchAll(PDO::FETCH_ASSOC)]);

    case 'GET:gastos':
        responder(['exito' => true, 'gastos' => finListarGastos($pdo, $_GET), 'categorias' => finCategoriasGasto($pdo),
                   'puede_cancelar' => finPuedeCancelar()]);

    case 'GET:rentabilidad':
        responder(['exito' => true, 'rentabilidad' => finRentabilidad($pdo, (string)($_GET['desde'] ?? date('Y-m-01')), (string)($_GET['hasta'] ?? date('Y-m-d')))]);

    case 'GET:estado_cuenta':
        responder(['exito' => true, 'estado' => finEstadoCuenta($pdo, (int)($_GET['cliente_id'] ?? 0)),
                   'empresa' => EmpresaContexto::$fila['nombre'] ?? '']);

    case 'GET:enlace_estado_cuenta':  // D-54
        $ecCliente = finCliente($pdo, (int)($_GET['cliente_id'] ?? 0));
        if (!$ecCliente) throw new FinanzasException('El cliente no existe.', 404);
        require_once __DIR__ . '/../config/enlaces.php';
        $url = enlaceUrl(enlaceFirmar($pdo, 'estado_cuenta', (int)EmpresaContexto::$id, (int)$ecCliente['id']));
        $texto = 'Hola, te comparto tu estado de cuenta con ' . (EmpresaContexto::$fila['nombre'] ?? 'nuestra empresa') . ": $url (el enlace vence el "
            . date('d/m/Y', time() + ENLACE_DIAS * 86400) . ').';
        if (empresaAdmiteEscrituras()) registrarAuditoria('clientes', (int)$ecCliente['id'], 'COMPARTIR', null, ['estado_de_cuenta' => true], $ecCliente['nombre']);  // H-08: un GET en solo lectura no escribe
        responder(['exito' => true, 'url' => $url, 'whatsapp' => enlaceWhatsapp($texto, (string)($ecCliente['telefono'] ?? '')), 'texto' => $texto]);

    case 'GET:exportar':
        $desde = finFecha($_GET['desde'] ?? date('Y-m-01'));
        $hasta = finFecha($_GET['hasta'] ?? date('Y-m-d'));
        $tipo = (string)($_GET['tipo'] ?? '');
        $sufijo = "_{$desde}_{$hasta}.csv";
        if ($tipo === 'cuentas') {
            $filas = array_map(fn($c) => [$c['folio'], $c['fecha'], $c['vencimiento'], $c['cliente_nombre'], $c['concepto'], $c['origen_folio'],
                $c['factura'], $c['subtotal'], $c['iva'], $c['retenciones'], $c['total'], $c['cobrado'], $c['saldo'], $c['estatus']],
                finListarCuentas($pdo, ['estado' => 'todas', 'desde' => $desde, 'hasta' => $hasta]));
            enviarCsv("cuentas_por_cobrar$sufijo", ['Folio', 'Fecha', 'Vencimiento', 'Cliente', 'Concepto', 'Origen', 'Factura',
                'Subtotal', 'IVA', 'Retenciones', 'Total', 'Cobrado', 'Saldo', 'Estatus'], $filas);
        }
        if ($tipo === 'cobros') {
            $st = $pdo->prepare('SELECT b.fecha, k.folio, k.cliente_nombre, k.factura, b.monto, b.forma, b.referencia, b.cancelado' . (finCreditoDisponible($pdo) ? ', b.tipo' : '') . '
                                 FROM cobros b JOIN cuentas_cobrar k ON k.id = b.cuenta_id
                                 WHERE b.fecha BETWEEN ? AND ? ORDER BY b.fecha, b.id');
            $st->execute([$desde, $hasta]);
            $filas = array_map(fn($b) => [$b['fecha'], ($b['tipo'] ?? 'cobro') === 'nota_credito' ? 'Nota de crédito' : 'Cobro', $b['folio'], $b['cliente_nombre'], $b['factura'], $b['monto'],
                ($b['tipo'] ?? 'cobro') === 'nota_credito' ? '' : (FIN_FORMAS[$b['forma']] ?? $b['forma']), $b['referencia'], $b['cancelado'] ? 'Cancelado' : ''], $st->fetchAll(PDO::FETCH_ASSOC));
            enviarCsv("cobros$sufijo", ['Fecha', 'Tipo', 'Cuenta', 'Cliente', 'Factura', 'Monto', 'Forma de pago', 'Referencia', 'Estado'], $filas);
        }
        if ($tipo === 'gastos') {
            $filas = array_map(fn($g) => [$g['fecha'], $g['categoria'], $g['concepto'], $g['proveedor'], finR($g['monto'] - $g['iva']), $g['iva'], $g['monto'],
                FIN_FORMAS[$g['forma']] ?? $g['forma'], $g['referencia'], $g['incidencia_folio'], $g['cancelado'] ? 'Cancelado' : ''],
                finListarGastos($pdo, ['desde' => $desde, 'hasta' => $hasta, 'incluir_cancelados' => 1]));
            enviarCsv("gastos$sufijo", ['Fecha', 'Categoría', 'Concepto', 'Proveedor', 'Subtotal', 'IVA', 'Total', 'Forma de pago',
                'Referencia', 'Orden de servicio', 'Estado'], $filas);
        }
        throw new FinanzasException('Tipo de exportación no válido.');

    case 'POST:crear_cuenta':
        responder(['exito' => true, 'cuenta' => finCrearCuenta($pdo, $body)]);

    case 'POST:editar_cuenta':
        responder(['exito' => true, 'cuenta' => finEditarCuenta($pdo, (int)($body['id'] ?? 0), $body)]);

    case 'POST:cancelar_cuenta':
        responder(['exito' => true, 'cuenta' => finCancelarCuenta($pdo, (int)($body['id'] ?? 0), (string)($body['motivo'] ?? ''))]);

    case 'POST:cobro':
        $comprobante = finGuardarComprobante($_FILES['comprobante'] ?? null, 'cobro');
        responder(['exito' => true, 'cuenta' => finRegistrarCobro($pdo, (int)($body['cuenta_id'] ?? 0), $body, $comprobante)]);

    case 'POST:comprobante_cobro':
        $comprobante = finGuardarComprobante($_FILES['comprobante'] ?? null, 'cobro');
        if (!$comprobante) throw new FinanzasException('Elige el archivo del comprobante.');
        responder(['exito' => true, 'cuenta' => finAdjuntarComprobanteCobro($pdo, (int)($body['id'] ?? 0), $comprobante)]);

    case 'POST:guardar_credito':  // D-48
        [$antes, $despues] = finGuardarCredito($pdo, $body);
        registrarAuditoria('clientes', (int)$body['cliente_id'], 'UPDATE', $antes, $despues, 'Crédito');
        responder(['exito' => true, 'mensaje' => 'Crédito del cliente guardado.', 'aviso_credito' => finAvisoCredito($pdo, (int)$body['cliente_id'])]);

    case 'POST:nota_credito':  // D-48
        responder(['exito' => true, 'mensaje' => 'Nota de crédito registrada.', 'cuenta' => finRegistrarNotaCredito($pdo, (int)($body['cuenta_id'] ?? 0), $body)]);

    case 'POST:cancelar_cobro':
        responder(['exito' => true, 'cuenta' => finCancelarCobro($pdo, (int)($body['id'] ?? 0), (string)($body['motivo'] ?? ''))]);

    case 'POST:guardar_gasto':
        $comprobante = finGuardarComprobante($_FILES['comprobante'] ?? null, 'gasto');
        responder(['exito' => true, 'gasto' => finGuardarGasto($pdo, (int)($body['id'] ?? 0), $body, $comprobante)]);

    case 'POST:cancelar_gasto':
        responder(['exito' => true, 'gasto' => finCancelarGasto($pdo, (int)($body['id'] ?? 0), (string)($body['motivo'] ?? ''))]);

    default:
        responder(['exito' => false, 'mensaje' => 'Acción no válida'], 400);
    }
} catch (FinanzasException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    finBorrarComprobante($comprobante);
    responder(['exito' => false, 'mensaje' => $e->getMessage()] + ($e->codigo ? ['codigo' => $e->codigo] : []), $e->http);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    finBorrarComprobante($comprobante);
    error_log('Finanzas: ' . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine());
    responder(['exito' => false, 'mensaje' => 'Error interno de finanzas.'], 500);
}
