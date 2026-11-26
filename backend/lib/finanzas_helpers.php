<?php
// Lógica del módulo de finanzas. La usan backend/finanzas.php y tests/.
//
// Pensado para cualquier empresa de servicio, sin suponer cómo factura:
//  - Cuenta por cobrar: lo que un cliente debe. Nace de una cotización
//    aprobada, de una orden de servicio, de una venta o se captura suelta.
//    La factura se emite fuera del sistema; aquí solo se anota su folio/UUID.
//  - Cobro: pago recibido, aplicado a UNA cuenta. Si un cliente paga varias
//    con una transferencia, se registra un cobro por cuenta con la misma referencia.
//  - Gasto: egreso de la empresa; si se liga a una orden de servicio cuenta
//    como costo directo en la rentabilidad.
//  - Importes en pesos. Una cotización en dólares se convierte con su tipo de cambio.
//  - Nada se borra: se cancela con motivo (solo el Administrador) y queda en el historial.
//
// Requiere $pdo (config/database.php), sesión (config/auth.php) y permisos.
require_once __DIR__ . '/../../auth/audit.php';
require_once __DIR__ . '/../../auth/permisos.php';

const FIN_FORMAS = ['transferencia' => 'Transferencia', 'efectivo' => 'Efectivo', 'tarjeta' => 'Tarjeta', 'cheque' => 'Cheque', 'otro' => 'Otro'];
const FIN_ORIGENES = ['cotizacion', 'incidencia', 'venta'];
const FIN_CATEGORIAS_GASTO = ['Refacciones y materiales', 'Combustible', 'Viáticos', 'Herramienta', 'Nómina', 'Renta',
    'Servicios (luz, agua, internet)', 'Publicidad', 'Impuestos y comisiones', 'Otros'];
const FIN_COMPROBANTE_MAX_BYTES = 5 * 1024 * 1024;
const FIN_COMPROBANTE_TIPOS = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
const FIN_PREFIJO = 'CXC';

class FinanzasException extends RuntimeException {
    public int $http;
    /** Código para la interfaz; "confirmar" = se puede repetir con forzar=1 (legítimo pero raro). */
    public ?string $codigo;
    public function __construct(string $mensaje, int $http = 400, ?string $codigo = null) {
        parent::__construct($mensaje);
        $this->http = $http;
        $this->codigo = $codigo;
    }
}

function finDisponible(PDO $pdo): bool {
    static $ok = null;
    if ($ok === null) {
        try { $ok = (bool)$pdo->query("SHOW TABLES LIKE 'cuentas_cobrar'")->fetchColumn(); }
        catch (Throwable $e) { $ok = false; }
    }
    return $ok;
}

function finExigir(bool $condicion, string $mensaje = 'Tu rol no tiene permiso para esta acción.'): void {
    if (!$condicion) throw new FinanzasException($mensaje, 403);
}

/** Cancelar cuentas, cobros o gastos: solo el Administrador. */
function finPuedeCancelar(): bool { return esAdministracion(); }
/** D-22: corregir un cobro (cancelarlo con motivo) lo pueden el Administrador y el Administrativo. */
function finPuedeCorregirCobro(): bool { return esAdministracion() || ($_SESSION['rol'] ?? '') === 'Administrativo'; }

/** L-24: 1,234.50 (miles) y 12,5 (decimal con coma) se leen igual que en inventario e importación. */
function finR($v): float {
    if (is_int($v) || is_float($v)) return round((float)$v, 2);
    $t = str_replace(['$', ' '], '', (string)$v);
    if (preg_match('/^-?\d{1,3}(,\d{3})+(\.\d+)?$/', $t)) $t = str_replace(',', '', $t);
    elseif (preg_match('/^-?\d+,\d{1,2}$/', $t)) $t = str_replace(',', '.', $t);
    else $t = str_replace(',', '', $t);
    return round((float)$t, 2);
}

function finFecha($v, string $campo = 'fecha'): string {
    $v = trim((string)$v);
    // Q-4: la fecha debe existir (strtotime acepta 2026-02-30)
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) throw new FinanzasException("La $campo no es válida.");
    return $v;
}

function finTexto(array $d, string $k, int $max): ?string {
    $v = trim((string)($d[$k] ?? ''));
    return $v === '' ? null : mb_substr($v, 0, $max);
}

function finUsuario(): array {
    return [(int)($_SESSION['user_id'] ?? 0) ?: null, $_SESSION['nombre'] ?? null];
}

/** Cliente del catálogo cuyo nombre coincide (las órdenes y ventas guardan el nombre). */
function finClientePorNombre(PDO $pdo, ?string $nombre): ?array {
    if (trim((string)$nombre) === '') return null;
    $st = $pdo->prepare('SELECT id, nombre, dias_credito FROM clientes WHERE UPPER(TRIM(nombre)) = UPPER(TRIM(?)) LIMIT 1');
    $st->execute([$nombre]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function finCliente(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare('SELECT id, nombre, rfc, telefono, email, direccion, dias_credito' . (finCreditoDisponible($pdo) ? ', limite_credito' : '') . ' FROM clientes WHERE id = ?');
    $st->execute([$id]);
    $c = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($c) $c['limite_credito'] = isset($c['limite_credito']) && $c['limite_credito'] !== null ? (float)$c['limite_credito'] : null;
    return $c;
}

// ---------------------------------------------------------------- crédito y notas de crédito (D-48)

/** ¿Ya se aplicó la migración 018 (límite de crédito y notas de crédito)? */
function finCreditoDisponible(PDO $pdo): bool {
    static $cache = [];
    $clave = EmpresaContexto::$id ?? 0;
    if (!isset($cache[$clave])) {
        try { $cache[$clave] = (bool)$pdo->query("SHOW COLUMNS FROM cobros LIKE 'tipo'")->fetch(); } catch (Throwable $e) { $cache[$clave] = false; }
    }
    return $cache[$clave];
}

/** Condición SQL para quedarse solo con dinero recibido (sin notas de crédito). */
function finSoloCobros(PDO $pdo, string $alias = ''): string {
    return finCreditoDisponible($pdo) ? " AND {$alias}tipo = 'cobro'" : '';
}

/** Saldo abierto del cliente (cuentas pendientes y parciales). */
function finSaldoCliente(PDO $pdo, int $clienteId): float {
    $st = $pdo->prepare("SELECT COALESCE(SUM(total - cobrado), 0) FROM cuentas_cobrar WHERE cliente_id = ? AND estatus IN ('pendiente', 'parcial')");
    $st->execute([$clienteId]);
    return finR($st->fetchColumn());
}

/**
 * Aviso si el saldo del cliente (más $adicional, p. ej. una cotización por aprobar) rebasa
 * su límite de crédito. Null si no tiene límite o no lo rebasa. No bloquea: solo avisa.
 */
function finAvisoCredito(PDO $pdo, int $clienteId, float $adicional = 0.0): ?string {
    if (!finCreditoDisponible($pdo)) return null;
    $c = finCliente($pdo, $clienteId);
    if (!$c || $c['limite_credito'] === null) return null;
    $saldo = finSaldoCliente($pdo, $clienteId);
    $total = finR($saldo + $adicional);
    if ($total <= $c['limite_credito'] + 0.005) return null;
    return sprintf('%s rebasa su límite de crédito: límite $%s, saldo por cobrar $%s%s.', $c['nombre'],
        number_format($c['limite_credito'], 2), number_format($saldo, 2),
        $adicional > 0 ? ' más $' . number_format($adicional, 2) . ' de esta cotización' : '');
}

/** Guarda días y límite de crédito del cliente (permiso de Finanzas). Devuelve [antes, después]. */
function finGuardarCredito(PDO $pdo, array $d): array {
    if (!finCreditoDisponible($pdo)) throw new FinanzasException('Falta aplicar la migración 018 (límite de crédito).');
    $c = finCliente($pdo, (int)($d['cliente_id'] ?? 0));
    if (!$c) throw new FinanzasException('El cliente no existe.', 404);
    $dias = max(0, min(365, (int)($d['dias_credito'] ?? $c['dias_credito'])));
    $limite = trim((string)($d['limite_credito'] ?? '')) === '' ? null : finR($d['limite_credito']);
    if ($limite !== null && $limite < 0) throw new FinanzasException('El límite de crédito no puede ser negativo.');
    $pdo->prepare('UPDATE clientes SET dias_credito = ?, limite_credito = ? WHERE id = ?')->execute([$dias, $limite, $c['id']]);
    return [['dias_credito' => (int)$c['dias_credito'], 'limite_credito' => $c['limite_credito']], ['dias_credito' => $dias, 'limite_credito' => $limite]];
}

/**
 * Nota de crédito: reduce el saldo de la cuenta como un cobro, pero no es dinero recibido.
 * $d: monto, motivo (obligatorio), referencia (folio o UUID del CFDI de egreso), fecha.
 * Se cancela con finCancelarCobro() (D-22: no se borra).
 */
function finRegistrarNotaCredito(PDO $pdo, int $cuentaId, array $d): array {
    if (!finCreditoDisponible($pdo)) throw new FinanzasException('Falta aplicar la migración 018 (notas de crédito).');
    finExigir(finPuedeCorregirCobro(), 'Solo el Administrador o el Administrativo registran notas de crédito.');
    $monto = finR($d['monto'] ?? 0);
    if ($monto <= 0) throw new FinanzasException('El monto de la nota de crédito debe ser mayor a cero.');
    $motivo = finTexto($d, 'motivo', 255);
    if (!$motivo) throw new FinanzasException('Escribe el motivo de la nota de crédito.');
    $fecha = finFecha($d['fecha'] ?? date('Y-m-d'));
    if ($fecha > date('Y-m-d')) throw new FinanzasException('La fecha de la nota de crédito no puede ser futura.');  // L-26
    [$uid, $unombre] = finUsuario();
    $pdo->beginTransaction();
    try {
        $c = finFila($pdo, $cuentaId, true);
        if (!$c) throw new FinanzasException('La cuenta no existe.', 404);
        if ($c['estatus'] === 'cancelada') throw new FinanzasException('La cuenta está cancelada.');
        $saldo = finR((float)$c['total'] - (float)$c['cobrado']);
        if ($monto > $saldo + 0.005) throw new FinanzasException('La nota de crédito ($' . number_format($monto, 2) . ') es mayor al saldo de la cuenta ($' . number_format($saldo, 2) . ').');
        $fila = ['cuenta_id' => $cuentaId, 'tipo' => 'nota_credito', 'fecha' => $fecha, 'monto' => $monto, 'forma' => 'otro',
                 'referencia' => finTexto($d, 'referencia', 100), 'notas' => $motivo, 'creado_por' => $uid, 'creado_por_nombre' => $unombre];
        $cols = implode(', ', array_map(fn($k) => "`$k`", array_keys($fila)));
        $pdo->prepare("INSERT INTO cobros ($cols) VALUES (" . implode(', ', array_fill(0, count($fila), '?')) . ')')->execute(array_values($fila));
        $notaId = (int)$pdo->lastInsertId();
        finRecalcular($pdo, $cuentaId);
        registrarAuditoria('cobros', $notaId, 'CREATE', null, $fila + ['cuenta' => $c['folio']], $c['folio']);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return finCuenta($pdo, $cuentaId);
}

// ---------------------------------------------------------------- comprobantes

/** Guarda un comprobante (PDF o imagen, 5 MB) en uploads/e<ID>/finanzas y devuelve su ruta. */
function finGuardarComprobante(?array $archivo, string $tipo): ?string {
    if (!$archivo || ($archivo['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if ($archivo['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($archivo['tmp_name'] ?? '')) {
        throw new FinanzasException('No se recibió el comprobante. Inténtalo de nuevo.');
    }
    if ($archivo['size'] > FIN_COMPROBANTE_MAX_BYTES) throw new FinanzasException('El comprobante pesa más de 5 MB.');
    $mime = function_exists('mime_content_type') ? @mime_content_type($archivo['tmp_name']) : null;
    $ext = FIN_COMPROBANTE_TIPOS[$mime] ?? null;
    if (!$ext) throw new FinanzasException('El comprobante debe ser PDF, JPG, PNG o WEBP.');
    $relativa = carpetaEmpresa('uploads') . '/finanzas';
    $dir = __DIR__ . '/../../' . $relativa;
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) throw new RuntimeException('No se pudo crear la carpeta de comprobantes.');
    $nombre = $tipo . '_' . date('Ymd') . '_' . bin2hex(random_bytes(5)) . '.' . $ext;
    if (!move_uploaded_file($archivo['tmp_name'], "$dir/$nombre")) throw new RuntimeException('No se pudo guardar el comprobante.');
    return "$relativa/$nombre";
}

function finBorrarComprobante(?string $ruta): void {
    if (!$ruta || strpos($ruta, carpetaEmpresa('uploads') . '/finanzas/') !== 0) return;
    $archivo = __DIR__ . '/../../' . $ruta;
    if (is_file($archivo)) @unlink($archivo);
}

// ---------------------------------------------------------------- orígenes

/**
 * Datos de una cotización, orden de servicio o venta para crear su cuenta.
 * En una cotización: lo aprobado (sin IVA e IVA, en pesos) y lo que ya se registró.
 */
/**
 * O-16: IVA que corresponde a $subtotal en una cuenta que viene de una cotización: la
 * misma proporción que lo aprobado (que puede mezclar 16 %, 8 % y exento). Si $subtotal
 * es todo lo que falta, el IVA es exactamente lo que falta (sin diferencias de redondeo).
 * $registrado: lo ya registrado en otras cuentas de esa cotización. Null si no aplica.
 */
function finIvaDeOrigen(array $o, float $subtotal, float $registrado): ?float {
    if (($o['tipo'] ?? '') !== 'cotizacion' || $o['limite'] === null || (float)$o['limite'] <= 0) return null;
    $falta = finR((float)$o['limite'] - $registrado);
    $base = finR($falta * (float)$o['subtotal'] / (float)$o['limite']);
    if (abs($subtotal - $base) < 0.005) return finR($falta - $base);
    return (float)$o['subtotal'] > 0 ? finR($subtotal * (float)$o['iva'] / (float)$o['subtotal']) : 0.0;
}

function finExigirIvaDeOrigen(array $o, array $imp, float $registrado): void {
    $esperado = finIvaDeOrigen($o, (float)$imp['subtotal'], $registrado);
    if ($esperado !== null && abs((float)$imp['iva'] - $esperado) > 0.02) {
        throw new FinanzasException(sprintf('El IVA de esta cuenta debe ser $%s: la cotización %s tiene su propia proporción de IVA (puede mezclar tasas o partidas exentas).',
            number_format($esperado, 2), $o['folio']));
    }
}

// ---------------------------------------------------------------- cobro de la orden (D-49)
// Una orden se cobra por UN solo camino: su cotización aprobada o, si no tiene, sus
// conceptos aplicados. El otro camino se bloquea para no cobrar dos veces.

/** Cotizaciones aprobadas (o convertidas) vigentes de la orden: [id => folio]. */
function finCotizacionesDeCobro(PDO $pdo, int $incidenciaId): array {
    $st = $pdo->prepare("SELECT id, folio FROM cotizaciones WHERE incidencia_id = ? AND estatus IN ('aprobada', 'convertida') AND reemplazada_por IS NULL ORDER BY id");
    $st->execute([$incidenciaId]);
    return array_column($st->fetchAll(PDO::FETCH_ASSOC), 'folio', 'id');
}

/**
 * Conceptos aplicados de la orden valuados a precio de venta: refacciones y equipos
 * (netos de devoluciones, al precio actual del catálogo) y servicios (al precio con que
 * se aplicaron). Devuelve ['subtotal' => ..., 'detalle' => [[descripcion, cantidad, importe]]].
 */
function finConceptosDeOrden(PDO $pdo, int $incidenciaId): array {
    require_once __DIR__ . '/inventario_helpers.php';
    $detalle = [];
    $hayTasaIva = invTieneTasaIva($pdo);
    try {
        // D-51: cada movimiento se valúa al precio con que se aplicó (migración 020)
        $precio = invTienePrecioCongelado($pdo) ? 'COALESCE(m.precio_unitario, a.precio_venta)' : 'a.precio_venta';
        $groupBy = $hayTasaIva ? 'GROUP BY m.articulo_id, a.nombre, a.tasa_iva' : 'GROUP BY m.articulo_id, a.nombre';
        $tasaCol = $hayTasaIva ? ', COALESCE(a.tasa_iva, 0.16) AS tasa_iva' : '';
        $st = $pdo->prepare("SELECT a.nombre, SUM(-m.cantidad) AS cantidad, SUM(-m.cantidad * $precio) AS importe$tasaCol
                             FROM inventario_movimientos m
                             JOIN inventario_articulos a ON a.id = m.articulo_id
                             WHERE m.referencia_tipo = 'incidencia' AND m.referencia_id = ?
                             $groupBy HAVING cantidad > 0 ORDER BY MIN(m.id)");
        $st->execute([$incidenciaId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $f) {
            $detalle[] = ['descripcion' => $f['nombre'], 'cantidad' => (float)$f['cantidad'],
                          'importe' => finR((float)$f['importe']),
                          'tasa_iva' => isset($f['tasa_iva']) ? (float)$f['tasa_iva'] : null];
        }
    } catch (PDOException $e) { /* sin inventario */ }
    try {
        $groupBy = $hayTasaIva ? 'GROUP BY s.articulo_id, s.descripcion, ia.tasa_iva' : 'GROUP BY s.articulo_id, s.descripcion';
        $join    = $hayTasaIva ? ' LEFT JOIN inventario_articulos ia ON ia.id = s.articulo_id' : '';
        $tasaCol = $hayTasaIva ? ', COALESCE(ia.tasa_iva, 0.16) AS tasa_iva' : '';
        $st = $pdo->prepare("SELECT s.descripcion, SUM(s.cantidad) AS cantidad, SUM(s.cantidad * s.precio_unitario) AS importe$tasaCol
                             FROM incidencia_servicios s$join
                             WHERE s.incidencia_id = ? $groupBy ORDER BY MIN(s.id)");
        $st->execute([$incidenciaId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $f) {
            $detalle[] = ['descripcion' => $f['descripcion'], 'cantidad' => (float)$f['cantidad'],
                          'importe' => finR($f['importe']),
                          'tasa_iva' => isset($f['tasa_iva']) ? (float)$f['tasa_iva'] : null];
        }
    } catch (PDOException $e) { /* sin la migración 015 */ }
    $subtotal  = finR(array_sum(array_column($detalle, 'importe')));
    $ivaDefecto = finIvaDefecto();
    $iva = finR(array_sum(array_map(fn($d) => $d['importe'] * ($d['tasa_iva'] ?? $ivaDefecto), $detalle)));
    return ['subtotal' => $subtotal, 'iva' => $iva, 'detalle' => $detalle];
}

/** IVA por defecto de la empresa (el de sus cotizaciones). */
function finIvaDefecto(): float {
    require_once __DIR__ . '/cotizaciones_helpers.php';
    return (float)(cotConfig()['iva'] ?? 0.16);
}

function finOrigen(PDO $pdo, string $tipo, int $id, bool $bloquear = false): array {
    if (!in_array($tipo, FIN_ORIGENES, true)) throw new FinanzasException('Origen no válido.');
    if ($tipo === 'incidencia') {
        // L-3: con $bloquear, la orden se bloquea primero (orden -> cotización, el mismo orden que al eliminarla)
        if ($bloquear) $pdo->prepare('SELECT id FROM incidencias WHERE id = ? FOR UPDATE')->execute([$id]);
        // D-49: con cotización aprobada, la orden se cobra con ella (H-09: salvo que la orden ya
        // tenga su cuenta propia; entonces un cargo adicional sigue siendo de la orden)
        $cots = finRegistradoDeOrigen($pdo, 'incidencia', $id) > 0 ? [] : finCotizacionesDeCobro($pdo, $id);
        if (count($cots) === 1) {
            return finOrigen($pdo, 'cotizacion', (int)array_key_first($cots), $bloquear) + ['desde_orden' => true];
        }
        if (count($cots) > 1) {
            throw new FinanzasException('Esta orden se cobra con sus cotizaciones aprobadas (' . implode(', ', $cots) . '): crea la cuenta desde cada cotización.');
        }
    }
    if ($tipo === 'cotizacion') {
        // C-H02: con $bloquear, dos cuentas simultáneas de la misma cotización se validan una tras otra
        $st = $pdo->prepare('SELECT id, folio, cliente_id, cliente_nombre, estatus, moneda, tipo_cambio, incidencia_id FROM cotizaciones WHERE id = ?' . ($bloquear ? ' FOR UPDATE' : ''));
        $st->execute([$id]);
        $c = $st->fetch(PDO::FETCH_ASSOC);
        if (!$c) throw new FinanzasException('La cotización no existe.', 404);
        if (!in_array($c['estatus'], ['aprobada', 'convertida'], true)) throw new FinanzasException('Solo se cobran cotizaciones aprobadas.');
        $st = $pdo->prepare('SELECT COALESCE(SUM(importe), 0) AS base, COALESCE(SUM(iva), 0) AS iva FROM cotizacion_partidas WHERE cotizacion_id = ? AND aceptada = 1');
        $st->execute([$id]);
        $a = $st->fetch(PDO::FETCH_ASSOC);
        $tc = $c['moneda'] === 'MXN' ? 1.0 : (float)$c['tipo_cambio'];
        $subtotal = finR((float)$a['base'] * $tc);
        $iva = finR((float)$a['iva'] * $tc);
        return [
            'tipo' => 'cotizacion', 'id' => (int)$c['id'], 'folio' => $c['folio'],
            'cliente_id' => $c['cliente_id'] ? (int)$c['cliente_id'] : null, 'cliente_nombre' => $c['cliente_nombre'],
            'concepto' => 'Cotización ' . $c['folio'] . ($c['moneda'] !== 'MXN' ? ' (' . $c['moneda'] . ' a T.C. ' . rtrim(rtrim(number_format($tc, 4), '0'), '.') . ')' : ''),
            'subtotal' => $subtotal, 'iva' => $iva, 'limite' => finR($subtotal + $iva),
            'registrado' => finRegistradoDeCotizacion($pdo, (int)$c['id']),  // H-03: incluye lo cobrado por sus ventas
        ];
    }
    if ($tipo === 'incidencia') {
        $st = $pdo->prepare('SELECT id, numero_incidente AS folio, cliente, falla FROM incidencias WHERE id = ?');
        $st->execute([$id]);
        $i = $st->fetch(PDO::FETCH_ASSOC);
        if (!$i) throw new FinanzasException('La orden de servicio no existe.', 404);
        $cli = finClientePorNombre($pdo, $i['cliente']);
        // D-49: sin cotización, el importe sale de los conceptos aplicados (se puede ajustar)
        $conceptos = finConceptosDeOrden($pdo, (int)$i['id']);
        $hay = $conceptos['subtotal'] > 0;
        return [
            'tipo' => 'incidencia', 'id' => (int)$i['id'], 'folio' => $i['folio'],
            'cliente_id' => $cli ? (int)$cli['id'] : null, 'cliente_nombre' => $cli['nombre'] ?? $i['cliente'],
            'concepto' => 'Orden de servicio ' . $i['folio'],
            'subtotal' => $hay ? $conceptos['subtotal'] : null, 'iva' => $hay ? $conceptos['iva'] : null,
            'detalle' => $conceptos['detalle'], 'limite' => null,
            'registrado' => finRegistradoDeOrigen($pdo, 'incidencia', (int)$i['id']),
        ];
    }
    $st = $pdo->prepare('SELECT id, folio, cliente FROM ventas WHERE id = ?' . ($bloquear ? ' FOR UPDATE' : ''));
    $st->execute([$id]);
    $v = $st->fetch(PDO::FETCH_ASSOC);
    if (!$v) throw new FinanzasException('La venta no existe.', 404);
    $cli = finClientePorNombre($pdo, $v['cliente']);
    // H-03 · D-55: la venta de una cotización aprobada comparte su monto aprobado (lo cobrado por la
    // cotización y por sus ventas no pasa de lo aprobado)
    $st = $pdo->prepare("SELECT c.id FROM cotizacion_ventas cv JOIN cotizaciones c ON c.id = cv.cotizacion_id
                         WHERE cv.venta_id = ? AND c.estatus IN ('aprobada', 'convertida') ORDER BY c.id LIMIT 1");
    $st->execute([$id]);
    if ($cotId = (int)$st->fetchColumn()) {
        $cot = finOrigen($pdo, 'cotizacion', $cotId, $bloquear);
        return [
            'tipo' => 'venta', 'id' => (int)$v['id'], 'folio' => $v['folio'],
            'cliente_id' => $cli ? (int)$cli['id'] : $cot['cliente_id'], 'cliente_nombre' => $cli['nombre'] ?? $v['cliente'],
            'concepto' => 'Venta ' . $v['folio'] . ' (cotización ' . $cot['folio'] . ')', 'subtotal' => null, 'iva' => null,
            'limite' => $cot['limite'], 'registrado' => $cot['registrado'], 'cotizacion_folio' => $cot['folio'],
        ];
    }
    return [
        'tipo' => 'venta', 'id' => (int)$v['id'], 'folio' => $v['folio'],
        'cliente_id' => $cli ? (int)$cli['id'] : null, 'cliente_nombre' => $cli['nombre'] ?? $v['cliente'],
        'concepto' => 'Venta ' . $v['folio'], 'subtotal' => null, 'iva' => null, 'limite' => null,
        'registrado' => finRegistradoDeOrigen($pdo, 'venta', (int)$v['id']),
    ];
}

/**
 * L-3 · H-03: qué filas bloquear para crear la cuenta de un origen, en el orden único orden ->
 * cotización -> venta. Se lee ANTES de abrir la transacción: en REPEATABLE READ la primera lectura
 * normal dentro de la transacción fija la foto de los datos, y si ocurre antes de esperar el candado
 * lo ya cobrado por otra petición simultánea no se vería (C-H02).
 */
function finPlanDeBloqueo(PDO $pdo, string $tipo, int $id): array {
    $inc = []; $cots = [];
    if ($tipo === 'incidencia') {
        $inc[] = $id;
        $cots = array_keys(finCotizacionesDeCobro($pdo, $id));
    } elseif ($tipo === 'cotizacion') {
        $cots[] = $id;
        $st = $pdo->prepare('SELECT incidencia_id FROM cotizaciones WHERE id = ?');
        $st->execute([$id]);
        if ($i = (int)$st->fetchColumn()) $inc[] = $i;
    } elseif ($tipo === 'venta') {
        $st = $pdo->prepare('SELECT c.id, c.incidencia_id FROM cotizacion_ventas cv JOIN cotizaciones c ON c.id = cv.cotizacion_id WHERE cv.venta_id = ?');
        $st->execute([$id]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) { $cots[] = (int)$c['id']; if ($c['incidencia_id']) $inc[] = (int)$c['incidencia_id']; }
    }
    $inc = array_values(array_unique(array_map('intval', $inc))); sort($inc);
    $cots = array_values(array_unique(array_map('intval', $cots))); sort($cots);
    return ['incidencias' => $inc, 'cotizaciones' => $cots];
}

/** Bloquea, dentro de la transacción, las filas del plan (solo lecturas con candado). */
function finAplicarBloqueo(PDO $pdo, array $plan): void {
    foreach ($plan['incidencias'] as $i) $pdo->prepare('SELECT id FROM incidencias WHERE id = ? FOR UPDATE')->execute([$i]);
    foreach ($plan['cotizaciones'] as $c) $pdo->prepare('SELECT id FROM cotizaciones WHERE id = ? FOR UPDATE')->execute([$c]);
}

/**
 * D-49: una cotización de una orden que ya tiene cuenta propia no se cobra aparte, y una
 * orden cuya cotización ya tiene cuenta no se cobra otra vez por sus conceptos.
 */
function finExigirUnSoloCobro(PDO $pdo, array $origen): void {
    if ($origen['tipo'] === 'cotizacion') {
        $st = $pdo->prepare("SELECT k.folio, i.numero_incidente FROM cotizaciones c JOIN incidencias i ON i.id = c.incidencia_id
                             JOIN cuentas_cobrar k ON k.origen_tipo = 'incidencia' AND k.origen_id = i.id AND k.estatus <> 'cancelada'
                             WHERE c.id = ? LIMIT 1");
        $st->execute([$origen['id']]);
        if ($f = $st->fetch(PDO::FETCH_ASSOC)) {
            throw new FinanzasException("La orden de servicio {$f['numero_incidente']} ya tiene su cuenta por cobrar ({$f['folio']}): esta cotización no se cobra aparte para no cobrar dos veces. Si la cuenta de la orden estaba mal, cancélala primero.");
        }
    } elseif ($origen['tipo'] === 'incidencia') {
        $st = $pdo->prepare("SELECT k.folio, c.folio AS cotizacion FROM cotizaciones c
                             JOIN cuentas_cobrar k ON k.origen_tipo = 'cotizacion' AND k.origen_id = c.id AND k.estatus <> 'cancelada'
                             WHERE c.incidencia_id = ? LIMIT 1");
        $st->execute([$origen['id']]);
        if ($f = $st->fetch(PDO::FETCH_ASSOC)) {
            throw new FinanzasException("Esta orden ya se cobra con la cotización {$f['cotizacion']} (cuenta {$f['folio']}): no se cobra otra vez por sus conceptos.");
        }
    }
    // L-2 · H-03: una venta registrada desde una cotización comparte el monto aprobado de la
    // cotización (finOrigen le pone ese límite); lo que no se permite es pasar de lo aprobado
    if ($origen['tipo'] === 'venta') {
        // H-04: ni si la orden de esa cotización ya tiene su cuenta propia
        $st = $pdo->prepare("SELECT k.folio, i.numero_incidente FROM cotizacion_ventas cv JOIN cotizaciones c ON c.id = cv.cotizacion_id
                             JOIN incidencias i ON i.id = c.incidencia_id
                             JOIN cuentas_cobrar k ON k.origen_tipo = 'incidencia' AND k.origen_id = i.id AND k.estatus <> 'cancelada'
                             WHERE cv.venta_id = ? LIMIT 1");
        $st->execute([$origen['id']]);
        if ($f = $st->fetch(PDO::FETCH_ASSOC)) {
            throw new FinanzasException("La orden de servicio {$f['numero_incidente']} de esta venta ya tiene su cuenta por cobrar ({$f['folio']}): no se cobra otra vez.");
        }
    }
    if ($origen['tipo'] === 'incidencia') {
        // H-04: ni la orden si una venta de sus cotizaciones ya se cobra
        $st = $pdo->prepare("SELECT k.folio, v.folio AS venta FROM cotizaciones c JOIN cotizacion_ventas cv ON cv.cotizacion_id = c.id
                             JOIN ventas v ON v.id = cv.venta_id
                             JOIN cuentas_cobrar k ON k.origen_tipo = 'venta' AND k.origen_id = v.id AND k.estatus <> 'cancelada'
                             WHERE c.incidencia_id = ? LIMIT 1");
        $st->execute([$origen['id']]);
        if ($f = $st->fetch(PDO::FETCH_ASSOC)) {
            throw new FinanzasException("Esta orden ya se cobra con la venta {$f['venta']} (cuenta {$f['folio']}): no se cobra otra vez por sus conceptos.");
        }
    }
}

/**
 * L-1: una orden o venta que ya tiene cuenta vigente no genera otra sin confirmar
 * (forzar=1, p. ej. un cargo adicional). Las cotizaciones ya tienen su límite.
 */
function finExigirConfirmarSegundaCuenta(PDO $pdo, array $origen, array $d): void {
    // (una venta de cotización ya tiene el límite de lo aprobado: no hace falta confirmar)
    if (!in_array($origen['tipo'], ['incidencia', 'venta'], true) || $origen['limite'] !== null || $origen['registrado'] <= 0 || !empty($d['forzar'])) return;
    $st = $pdo->prepare("SELECT folio, total FROM cuentas_cobrar WHERE origen_tipo = ? AND origen_id = ? AND estatus <> 'cancelada' ORDER BY id");
    $st->execute([$origen['tipo'], $origen['id']]);
    $cuentas = array_map(fn($c) => $c['folio'] . ' por $' . number_format((float)$c['total'], 2), $st->fetchAll(PDO::FETCH_ASSOC));
    $que = $origen['tipo'] === 'venta' ? 'La venta' : 'La orden de servicio';
    throw new FinanzasException("$que {$origen['folio']} ya tiene la cuenta " . implode(', ', $cuentas)
        . '. ¿Registrar otra cuenta (por ejemplo, un cargo adicional)?', 409, 'confirmar');
}

/** H-03: lo cobrado de una cotización, por ella misma y por las ventas que se registraron desde ella. */
function finRegistradoDeCotizacion(PDO $pdo, int $cotId): float {
    $st = $pdo->prepare("SELECT COALESCE(SUM(k.total), 0) FROM cuentas_cobrar k WHERE k.estatus <> 'cancelada' AND (
                             (k.origen_tipo = 'cotizacion' AND k.origen_id = ?)
                          OR (k.origen_tipo = 'venta' AND k.origen_id IN (SELECT venta_id FROM cotizacion_ventas WHERE cotizacion_id = ? AND venta_id IS NOT NULL)))");
    $st->execute([$cotId, $cotId]);
    return finR($st->fetchColumn());
}

/** Total de las cuentas vigentes (no canceladas) de un origen. */
function finRegistradoDeOrigen(PDO $pdo, string $tipo, int $id): float {
    $st = $pdo->prepare("SELECT COALESCE(SUM(total), 0) FROM cuentas_cobrar WHERE origen_tipo = ? AND origen_id = ? AND estatus <> 'cancelada'");
    $st->execute([$tipo, $id]);
    return finR($st->fetchColumn());
}

/** Busca órdenes de servicio y ventas por folio o cliente para ligar una cuenta o un gasto. */
function finBuscarOrigen(PDO $pdo, string $q, bool $soloOrdenes = false): array {
    $q = trim($q);
    if (mb_strlen($q) < 2) return [];
    $like = '%' . $q . '%';
    $st = $pdo->prepare('SELECT id, numero_incidente AS folio, cliente, fecha FROM incidencias
                         WHERE numero_incidente LIKE ? OR cliente LIKE ? ORDER BY id DESC LIMIT 10');
    $st->execute([$like, $like]);
    $res = array_map(fn($r) => ['tipo' => 'incidencia', 'id' => (int)$r['id'], 'folio' => $r['folio'], 'cliente' => $r['cliente'], 'fecha' => $r['fecha']],
        $st->fetchAll(PDO::FETCH_ASSOC));
    if (!$soloOrdenes) {
        $st = $pdo->prepare('SELECT id, folio, cliente, fecha_registro AS fecha FROM ventas WHERE folio LIKE ? OR cliente LIKE ? ORDER BY id DESC LIMIT 10');
        $st->execute([$like, $like]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $res[] = ['tipo' => 'venta', 'id' => (int)$r['id'], 'folio' => $r['folio'], 'cliente' => $r['cliente'], 'fecha' => $r['fecha']];
        }
    }
    return $res;
}

/**
 * Lo que ya se trabajó y aún no tiene cuenta por cobrar: cotizaciones
 * aprobadas con saldo sin registrar y órdenes cerradas con factura sin cuenta
 * (ni propia ni de una cotización ligada).
 */
function finPendientesDeRegistrar(PDO $pdo, int $limite = 50): array {
    $res = [];
    $st = $pdo->query("SELECT c.id, c.folio, c.cliente_nombre, c.fecha_aprobacion, c.total_aprobado, c.moneda, c.tipo_cambio,
                              (SELECT COALESCE(SUM(k.total), 0) FROM cuentas_cobrar k
                                WHERE k.estatus <> 'cancelada' AND ((k.origen_tipo = 'cotizacion' AND k.origen_id = c.id)
                                   OR (k.origen_tipo = 'venta' AND k.origen_id IN (SELECT venta_id FROM cotizacion_ventas WHERE cotizacion_id = c.id AND venta_id IS NOT NULL)))) AS registrado
                       FROM cotizaciones c
                       WHERE c.estatus IN ('aprobada', 'convertida') AND c.reemplazada_por IS NULL
                         -- D-49: si su orden ya se cobró directamente, la cotización no queda pendiente
                         AND NOT EXISTS (SELECT 1 FROM cuentas_cobrar k2 WHERE k2.origen_tipo = 'incidencia' AND k2.origen_id = c.incidencia_id AND k2.estatus <> 'cancelada')
                       ORDER BY c.fecha_aprobacion DESC LIMIT 200");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $tc = $c['moneda'] === 'MXN' ? 1.0 : (float)$c['tipo_cambio'];
        $aprobado = finR((float)$c['total_aprobado'] * $tc);
        $falta = finR($aprobado - (float)$c['registrado']);
        if ($falta <= 0.01) continue;
        $res[] = ['tipo' => 'cotizacion', 'id' => (int)$c['id'], 'folio' => $c['folio'], 'cliente' => $c['cliente_nombre'],
                  'fecha' => substr((string)$c['fecha_aprobacion'], 0, 10), 'importe' => $falta,
                  'detalle' => (float)$c['registrado'] > 0 ? 'Falta registrar parte de lo aprobado' : 'Cotización aprobada'];
    }
    $st = $pdo->query("SELECT i.id, i.numero_incidente, i.cliente, i.fecha
                       FROM incidencias i
                       WHERE LOWER(i.estatus) = 'cerrado con factura'
                         AND NOT EXISTS (SELECT 1 FROM cuentas_cobrar k WHERE k.origen_tipo = 'incidencia' AND k.origen_id = i.id AND k.estatus <> 'cancelada')
                         AND NOT EXISTS (SELECT 1 FROM cotizaciones c JOIN cuentas_cobrar k ON k.origen_tipo = 'cotizacion' AND k.origen_id = c.id AND k.estatus <> 'cancelada'
                                         WHERE c.incidencia_id = i.id)
                         AND NOT EXISTS (SELECT 1 FROM cotizaciones c3 JOIN cotizacion_ventas cv ON cv.cotizacion_id = c3.id
                                         JOIN cuentas_cobrar k ON k.origen_tipo = 'venta' AND k.origen_id = cv.venta_id AND k.estatus <> 'cancelada'
                                         WHERE c3.incidencia_id = i.id)
                         -- D-49: con cotización aprobada, la pendiente es la cotización (no la orden)
                         AND NOT EXISTS (SELECT 1 FROM cotizaciones c2 WHERE c2.incidencia_id = i.id AND c2.estatus IN ('aprobada', 'convertida') AND c2.reemplazada_por IS NULL)
                       ORDER BY i.fecha DESC, i.id DESC LIMIT 100");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $i) {
        $conceptos = finConceptosDeOrden($pdo, (int)$i['id']);
        $sub = $conceptos['subtotal'];
        $res[] = ['tipo' => 'incidencia', 'id' => (int)$i['id'], 'folio' => $i['numero_incidente'], 'cliente' => $i['cliente'],
                  'fecha' => $i['fecha'], 'importe' => $sub > 0 ? finR($sub + $conceptos['iva']) : null,
                  'detalle' => $sub > 0 ? 'Orden cerrada con factura · conceptos aplicados' : 'Orden cerrada con factura (sin conceptos aplicados)'];
    }
    usort($res, fn($a, $b) => strcmp((string)$b['fecha'], (string)$a['fecha']));
    return array_slice($res, 0, $limite);
}

// ---------------------------------------------------------------- cuentas por cobrar

function finSiguienteFolio(PDO $pdo): string {
    $st = $pdo->query("SELECT folio FROM cuentas_cobrar ORDER BY id DESC LIMIT 1 FOR UPDATE");
    $ultimo = (string)$st->fetchColumn();
    $n = preg_match('/(\d+)$/', $ultimo, $m) ? (int)$m[1] : 0;
    $st = $pdo->prepare('SELECT COUNT(*) FROM cuentas_cobrar WHERE folio = ?');
    do {
        $folio = FIN_PREFIJO . '-' . str_pad((string)++$n, 4, '0', STR_PAD_LEFT);
        $st->execute([$folio]);
    } while ((int)$st->fetchColumn());
    return $folio;
}

/** Valida importes: total = subtotal + IVA − retenciones, mayor a cero. */
function finImportes(array $d): array {
    $subtotal = finR($d['subtotal'] ?? 0);
    $iva = finR($d['iva'] ?? 0);
    $ret = finR($d['retenciones'] ?? 0);
    if ($subtotal < 0 || $iva < 0 || $ret < 0) throw new FinanzasException('Los importes no pueden ser negativos.');
    $total = finR($subtotal + $iva - $ret);
    if ($total <= 0) throw new FinanzasException('El total a cobrar debe ser mayor a cero.');
    return ['subtotal' => $subtotal, 'iva' => $iva, 'retenciones' => $ret, 'total' => $total];
}

function finFila(PDO $pdo, int $id, bool $bloquear = false): ?array {
    $st = $pdo->prepare('SELECT * FROM cuentas_cobrar WHERE id = ?' . ($bloquear ? ' FOR UPDATE' : ''));
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Alta de cuenta por cobrar. $d: cliente_id, concepto, origen_tipo, origen_id,
 * fecha, vencimiento (vacío = fecha + días de crédito del cliente), subtotal,
 * iva, retenciones, factura, notas, guardar_credito (guarda los días en el cliente).
 */
function finCrearCuenta(PDO $pdo, array $d): array {
    $cliente = finCliente($pdo, (int)($d['cliente_id'] ?? 0));
    if (!$cliente) throw new FinanzasException('Selecciona un cliente del catálogo.');
    $concepto = finTexto($d, 'concepto', 255);
    if (!$concepto) throw new FinanzasException('Escribe el concepto de la cuenta.');
    $imp = finImportes($d);
    $fecha = finFecha($d['fecha'] ?? date('Y-m-d'));
    $dias = isset($d['dias_credito']) && $d['dias_credito'] !== '' ? max(0, min(365, (int)$d['dias_credito'])) : (int)$cliente['dias_credito'];
    $vencimiento = trim((string)($d['vencimiento'] ?? '')) !== ''
        ? finFecha($d['vencimiento'], 'fecha de vencimiento')
        : date('Y-m-d', strtotime("$fecha +$dias days"));
    if ($vencimiento < $fecha) throw new FinanzasException('El vencimiento no puede ser anterior a la fecha.');

    [$uid, $unombre] = finUsuario();
    $plan = !empty($d['origen_tipo']) && !empty($d['origen_id']) && in_array($d['origen_tipo'], FIN_ORIGENES, true)
        ? finPlanDeBloqueo($pdo, (string)$d['origen_tipo'], (int)$d['origen_id']) : null;  // antes de la transacción
    $pdo->beginTransaction();
    try {
        // C-H02: el límite de lo aprobado se valida dentro de la transacción, con la cotización bloqueada
        $origen = null;
        if ($plan !== null) finAplicarBloqueo($pdo, $plan);
        if (!empty($d['origen_tipo']) && !empty($d['origen_id'])) {
            $origen = finOrigen($pdo, (string)$d['origen_tipo'], (int)$d['origen_id'], true);
            finExigirUnSoloCobro($pdo, $origen);  // D-49
            finExigirConfirmarSegundaCuenta($pdo, $origen, $d);  // L-1
            if ($origen['limite'] !== null && $origen['registrado'] + $imp['total'] > $origen['limite'] + 0.01) {
                throw new FinanzasException(sprintf('La cotización %s tiene aprobados $%s y ya hay cuentas por $%s (de la cotización y de sus ventas): solo faltan $%s.',
                    $origen['cotizacion_folio'] ?? $origen['folio'], number_format($origen['limite'], 2), number_format($origen['registrado'], 2),
                    number_format(max(0, $origen['limite'] - $origen['registrado']), 2)));
            }
            finExigirIvaDeOrigen($origen, $imp, (float)$origen['registrado']);  // O-16
        }
        $fila = [
            'folio' => finSiguienteFolio($pdo),
            'cliente_id' => (int)$cliente['id'], 'cliente_nombre' => $cliente['nombre'],
            'concepto' => $concepto,
            'origen_tipo' => $origen['tipo'] ?? null, 'origen_id' => $origen['id'] ?? null, 'origen_folio' => $origen['folio'] ?? null,
            'fecha' => $fecha, 'vencimiento' => $vencimiento,
        ] + $imp + [
            'factura' => finTexto($d, 'factura', 100),
            'notas' => finTexto($d, 'notas', 2000),
            'creado_por' => $uid, 'creado_por_nombre' => $unombre,
        ];
        $cols = implode(', ', array_map(fn($c) => "`$c`", array_keys($fila)));
        $pdo->prepare("INSERT INTO cuentas_cobrar ($cols) VALUES (" . implode(', ', array_fill(0, count($fila), '?')) . ')')->execute(array_values($fila));
        $id = (int)$pdo->lastInsertId();
        if (!empty($d['guardar_credito']) && $dias !== (int)$cliente['dias_credito']) {
            $pdo->prepare('UPDATE clientes SET dias_credito = ? WHERE id = ?')->execute([$dias, $cliente['id']]);
        }
        // H-06 · D-55: la cuenta adicional confirmada queda marcada en el historial
        $adicional = $origen && in_array($origen['tipo'], ['incidencia', 'venta'], true) && $origen['registrado'] > 0
            && $origen['limite'] === null && !empty($d['forzar'])  // L2-9: solo si de verdad se confirmó
            ? ['cuenta_adicional_confirmada' => true, 'registrado_previo' => $origen['registrado']] : [];
        registrarAuditoria('cuentas_cobrar', $id, 'CREATE', null, $fila + $adicional, $fila['folio']);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return finCuenta($pdo, $id) + ['aviso_credito' => finAvisoCredito($pdo, (int)$cliente['id'])];  // D-48: avisa, no bloquea
}

/** Edita una cuenta vigente. Los importes solo cambian si aún no tiene cobros. */
function finEditarCuenta(PDO $pdo, int $id, array $d): array {
    // H-01 (fase 4): la cuenta de una cotización o de su venta se edita con el mismo plan de bloqueo que
    // al crearla (orden -> cotización -> venta), leído antes de abrir la transacción
    $previa = finFila($pdo, $id);
    $plan = $previa && in_array($previa['origen_tipo'], FIN_ORIGENES, true) && $previa['origen_id']
        ? finPlanDeBloqueo($pdo, (string)$previa['origen_tipo'], (int)$previa['origen_id']) : null;
    $pdo->beginTransaction();
    try {
        if ($plan !== null) finAplicarBloqueo($pdo, $plan);
        $c = finFila($pdo, $id, true);
        if (!$c) throw new FinanzasException('La cuenta no existe.', 404);
        if ($c['estatus'] === 'cancelada') throw new FinanzasException('La cuenta está cancelada.');
        $cambios = [
            'concepto' => finTexto($d, 'concepto', 255) ?? $c['concepto'],
            'vencimiento' => !empty($d['vencimiento']) ? finFecha($d['vencimiento'], 'fecha de vencimiento') : $c['vencimiento'],
            'factura' => finTexto($d, 'factura', 100),
            'notas' => finTexto($d, 'notas', 2000),
        ];
        if ($cambios['vencimiento'] < $c['fecha']) throw new FinanzasException('El vencimiento no puede ser anterior a la fecha.');
        if (isset($d['subtotal'])) {
            $imp = finImportes($d);
            $cambio = abs($imp['total'] - (float)$c['total']) > 0.001 || abs($imp['iva'] - (float)$c['iva']) > 0.001 || abs($imp['retenciones'] - (float)$c['retenciones']) > 0.001;
            if ($cambio) {
                if ((float)$c['cobrado'] > 0) throw new FinanzasException('La cuenta ya tiene cobros: para cambiar los importes cancela primero los cobros.');
                if (in_array($c['origen_tipo'], ['cotizacion', 'venta'], true) && $c['origen_id']) {
                    // H-01: la cuenta de una venta de cotización comparte lo aprobado (D-55) también al editarla
                    $o = finOrigen($pdo, $c['origen_tipo'], (int)$c['origen_id'], true);
                    if ($o['limite'] !== null && $o['registrado'] - (float)$c['total'] + $imp['total'] > $o['limite'] + 0.01) {
                        throw new FinanzasException('El total rebasa lo aprobado en la cotización ' . ($o['cotizacion_folio'] ?? $o['folio']) . '.');
                    }
                    if ($c['origen_tipo'] === 'cotizacion') finExigirIvaDeOrigen($o, $imp, (float)$o['registrado'] - (float)$c['total']);  // O-16
                }
                $cambios += $imp;
            }
        }
        $sets = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($cambios)));
        $pdo->prepare("UPDATE cuentas_cobrar SET $sets, actualizado_en = NOW() WHERE id = ?")->execute([...array_values($cambios), $id]);
        registrarAuditoria('cuentas_cobrar', $id, 'UPDATE', array_intersect_key($c, $cambios), $cambios, $c['folio']);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return finCuenta($pdo, $id);
}

/** Recalcula lo cobrado y el estatus de una cuenta (dentro de la transacción del cobro). */
function finRecalcular(PDO $pdo, int $id): void {
    $st = $pdo->prepare('SELECT COALESCE(SUM(monto), 0) FROM cobros WHERE cuenta_id = ? AND cancelado = 0');
    $st->execute([$id]);
    $cobrado = finR($st->fetchColumn());
    $pdo->prepare("UPDATE cuentas_cobrar SET cobrado = ?,
                       estatus = CASE WHEN estatus = 'cancelada' THEN 'cancelada'
                                      WHEN ? >= total - 0.005 THEN 'pagada'
                                      WHEN ? > 0 THEN 'parcial' ELSE 'pendiente' END,
                       actualizado_en = NOW()
                   WHERE id = ?")->execute([$cobrado, $cobrado, $cobrado, $id]);
}

/** Registra un cobro. $d: fecha, monto, forma, referencia, notas. */
function finRegistrarCobro(PDO $pdo, int $cuentaId, array $d, ?string $comprobante = null): array {
    $monto = finR($d['monto'] ?? 0);
    if ($monto <= 0) throw new FinanzasException('El monto del cobro debe ser mayor a cero.');
    $fecha = finFecha($d['fecha'] ?? date('Y-m-d'));
    if ($fecha > date('Y-m-d')) throw new FinanzasException('La fecha del cobro no puede ser futura.');
    $forma = (string)($d['forma'] ?? 'transferencia');
    if (!isset(FIN_FORMAS[$forma])) throw new FinanzasException('Forma de pago no válida.');
    [$uid, $unombre] = finUsuario();
    $pdo->beginTransaction();
    try {
        $c = finFila($pdo, $cuentaId, true);
        if (!$c) throw new FinanzasException('La cuenta no existe.', 404);
        if ($c['estatus'] === 'cancelada') throw new FinanzasException('La cuenta está cancelada.');
        $saldo = finR((float)$c['total'] - (float)$c['cobrado']);
        if ($saldo <= 0) throw new FinanzasException('La cuenta ya está pagada.');
        if ($fecha < $c['fecha']) throw new FinanzasException('La fecha del cobro no puede ser anterior a la de la cuenta (' . date('d/m/Y', strtotime($c['fecha'])) . ').');  // L-26
        if ($monto > $saldo + 0.005) throw new FinanzasException('El cobro ($' . number_format($monto, 2) . ') es mayor al saldo de la cuenta ($' . number_format($saldo, 2) . ').');
        $fila = ['cuenta_id' => $cuentaId, 'fecha' => $fecha, 'monto' => $monto, 'forma' => $forma,
                 'referencia' => finTexto($d, 'referencia', 100), 'notas' => finTexto($d, 'notas', 255),
                 'comprobante' => $comprobante, 'creado_por' => $uid, 'creado_por_nombre' => $unombre];
        $cols = implode(', ', array_map(fn($k) => "`$k`", array_keys($fila)));
        $pdo->prepare("INSERT INTO cobros ($cols) VALUES (" . implode(', ', array_fill(0, count($fila), '?')) . ')')->execute(array_values($fila));
        $cobroId = (int)$pdo->lastInsertId();
        finRecalcular($pdo, $cuentaId);
        registrarAuditoria('cobros', $cobroId, 'CREATE', null, $fila + ['cuenta' => $c['folio']], $c['folio']);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return finCuenta($pdo, $cuentaId);
}

function finCancelarCobro(PDO $pdo, int $cobroId, string $motivo): array {
    finExigir(finPuedeCorregirCobro(), 'Solo el Administrador o el Administrativo pueden cancelar cobros.');
    $motivo = trim($motivo);
    if ($motivo === '') throw new FinanzasException('Escribe el motivo de la cancelación.');
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT * FROM cobros WHERE id = ? FOR UPDATE');
        $st->execute([$cobroId]);
        $cobro = $st->fetch(PDO::FETCH_ASSOC);
        if (!$cobro) throw new FinanzasException('El cobro no existe.', 404);
        if ((int)$cobro['cancelado'] === 1) throw new FinanzasException('El cobro ya está cancelado.');
        $c = finFila($pdo, (int)$cobro['cuenta_id'], true);
        $pdo->prepare('UPDATE cobros SET cancelado = 1, motivo_cancelacion = ? WHERE id = ?')->execute([mb_substr($motivo, 0, 255), $cobroId]);
        finRecalcular($pdo, (int)$cobro['cuenta_id']);
        registrarAuditoria('cobros', $cobroId, 'CANCELAR', $cobro, ['cancelado' => 1, 'motivo' => $motivo], $c['folio'] ?? null);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return finCuenta($pdo, (int)$cobro['cuenta_id']);
}

/**
 * O-1: adjunta o reemplaza el comprobante de un cobro ya registrado (vigente). El
 * archivo anterior no se borra: queda en el historial. Devuelve la cuenta del cobro.
 */
function finAdjuntarComprobanteCobro(PDO $pdo, int $cobroId, string $comprobante): array {
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT id, cuenta_id, comprobante, cancelado FROM cobros WHERE id = ? FOR UPDATE');
        $st->execute([$cobroId]);
        $cobro = $st->fetch(PDO::FETCH_ASSOC);
        if (!$cobro) throw new FinanzasException('El cobro no existe.', 404);
        if ((int)$cobro['cancelado'] === 1) throw new FinanzasException('El cobro está cancelado: no se le puede adjuntar un comprobante.');
        $c = finFila($pdo, (int)$cobro['cuenta_id'], true);
        $pdo->prepare('UPDATE cobros SET comprobante = ? WHERE id = ?')->execute([$comprobante, $cobroId]);
        registrarAuditoria('cobros', $cobroId, 'COMPROBANTE', ['comprobante' => $cobro['comprobante']],
            ['comprobante' => $comprobante, 'reemplazo' => $cobro['comprobante'] !== null && $cobro['comprobante'] !== ''], $c['folio'] ?? null);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return finCuenta($pdo, (int)$cobro['cuenta_id']);
}

function finCancelarCuenta(PDO $pdo, int $id, string $motivo): array {
    finExigir(finPuedeCancelar(), 'Solo el administrador puede cancelar cuentas por cobrar.');
    $motivo = trim($motivo);
    if ($motivo === '') throw new FinanzasException('Escribe el motivo de la cancelación.');
    $pdo->beginTransaction();
    try {
        $c = finFila($pdo, $id, true);
        if (!$c) throw new FinanzasException('La cuenta no existe.', 404);
        if ($c['estatus'] === 'cancelada') throw new FinanzasException('La cuenta ya está cancelada.');
        if ((float)$c['cobrado'] > 0) throw new FinanzasException('La cuenta tiene cobros registrados: cancélalos primero.');
        $pdo->prepare("UPDATE cuentas_cobrar SET estatus = 'cancelada', motivo_cancelacion = ?, actualizado_en = NOW() WHERE id = ?")
            ->execute([mb_substr($motivo, 0, 255), $id]);
        registrarAuditoria('cuentas_cobrar', $id, 'CANCELAR', ['estatus' => $c['estatus']], ['estatus' => 'cancelada', 'motivo' => $motivo], $c['folio']);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return finCuenta($pdo, $id);
}

/** Días de atraso (0 si no está vencida o ya no tiene saldo). */
function finDiasVencida(array $c, ?string $hoy = null): int {
    if (!in_array($c['estatus'], ['pendiente', 'parcial'], true)) return 0;
    $hoy ??= date('Y-m-d');
    // L-23: días de calendario (no segundos / 86400, que pierde un día con el cambio de horario)
    return $c['vencimiento'] < $hoy ? (int)(new DateTimeImmutable($c['vencimiento']))->diff(new DateTimeImmutable($hoy))->days : 0;
}

function finDecorar(array $c): array {
    $c['saldo'] = $c['estatus'] === 'cancelada' ? 0.0 : finR((float)$c['total'] - (float)$c['cobrado']);
    $c['dias_vencida'] = finDiasVencida($c);
    return $c;
}

function finCuenta(PDO $pdo, int $id): array {
    $c = finFila($pdo, $id);
    if (!$c) throw new FinanzasException('La cuenta no existe.', 404);
    $st = $pdo->prepare('SELECT * FROM cobros WHERE cuenta_id = ? ORDER BY fecha, id');
    $st->execute([$id]);
    $c = finDecorar($c);
    $c['cobros'] = $st->fetchAll(PDO::FETCH_ASSOC);
    $c['puede_cancelar'] = finPuedeCancelar();
    $c['puede_corregir_cobro'] = finPuedeCorregirCobro();
    return $c;
}

/** $f: buscar, estado (abiertas | vencidas | pagadas | canceladas | todas), cliente_id, desde, hasta. */
function finListarCuentas(PDO $pdo, array $f): array {
    $w = ['1 = 1'];
    $p = [];
    $hoy = date('Y-m-d');
    switch ($f['estado'] ?? 'abiertas') {
        case 'abiertas': $w[] = "estatus IN ('pendiente', 'parcial')"; break;
        case 'vencidas': $w[] = "estatus IN ('pendiente', 'parcial') AND vencimiento < ?"; $p[] = $hoy; break;
        case 'pagadas': $w[] = "estatus = 'pagada'"; break;
        case 'canceladas': $w[] = "estatus = 'cancelada'"; break;
    }
    if (!empty($f['buscar'])) {
        $w[] = '(folio LIKE ? OR cliente_nombre LIKE ? OR concepto LIKE ? OR factura LIKE ? OR origen_folio LIKE ?)';
        array_push($p, ...array_fill(0, 5, '%' . $f['buscar'] . '%'));
    }
    if (!empty($f['cliente_id'])) { $w[] = 'cliente_id = ?'; $p[] = (int)$f['cliente_id']; }
    if (!empty($f['desde'])) { $w[] = 'fecha >= ?'; $p[] = finFecha($f['desde']); }
    if (!empty($f['hasta'])) { $w[] = 'fecha <= ?'; $p[] = finFecha($f['hasta']); }
    $st = $pdo->prepare('SELECT * FROM cuentas_cobrar WHERE ' . implode(' AND ', $w) . ' ORDER BY vencimiento ASC, id DESC LIMIT 500');
    $st->execute($p);
    return array_map('finDecorar', $st->fetchAll(PDO::FETCH_ASSOC));
}

// ---------------------------------------------------------------- gastos

function finGastoFila(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare('SELECT * FROM gastos WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Alta (id = 0) o edición de un gasto. $d: fecha, categoria, concepto,
 * proveedor, monto (total pagado), iva, forma, referencia, incidencia_folio.
 */
function finGuardarGasto(PDO $pdo, int $id, array $d, ?string $comprobante = null): array {
    $fecha = finFecha($d['fecha'] ?? date('Y-m-d'));
    if ($fecha > date('Y-m-d')) throw new FinanzasException('La fecha del gasto no puede ser futura.');  // L-26
    $categoria = finTexto($d, 'categoria', 60);
    if (!$categoria) throw new FinanzasException('Elige o escribe la categoría del gasto.');
    $concepto = finTexto($d, 'concepto', 255);
    if (!$concepto) throw new FinanzasException('Escribe el concepto del gasto.');
    $monto = finR($d['monto'] ?? 0);
    if ($monto <= 0) throw new FinanzasException('El monto del gasto debe ser mayor a cero.');
    $iva = finR($d['iva'] ?? 0);
    if ($iva < 0 || $iva >= $monto) throw new FinanzasException('El IVA debe ser menor al monto total.');
    $forma = (string)($d['forma'] ?? 'transferencia');
    if (!isset(FIN_FORMAS[$forma])) throw new FinanzasException('Forma de pago no válida.');
    $incId = null; $incFolio = null;
    $folio = trim((string)($d['incidencia_folio'] ?? ''));
    if ($folio !== '') {
        $st = $pdo->prepare('SELECT id, numero_incidente FROM incidencias WHERE numero_incidente = ? LIMIT 1');
        $st->execute([$folio]);
        $inc = $st->fetch(PDO::FETCH_ASSOC);
        if (!$inc) throw new FinanzasException("No existe la orden de servicio $folio.");
        [$incId, $incFolio] = [(int)$inc['id'], $inc['numero_incidente']];
    }
    $fila = ['fecha' => $fecha, 'categoria' => $categoria, 'concepto' => $concepto, 'proveedor' => finTexto($d, 'proveedor', 150),
             'monto' => $monto, 'iva' => $iva, 'forma' => $forma, 'referencia' => finTexto($d, 'referencia', 100),
             'incidencia_id' => $incId, 'incidencia_folio' => $incFolio];
    if ($comprobante) $fila['comprobante'] = $comprobante;

    if ($id) {
        $antes = finGastoFila($pdo, $id);
        if (!$antes) throw new FinanzasException('El gasto no existe.', 404);
        if ((int)$antes['cancelado'] === 1) throw new FinanzasException('El gasto está cancelado.');
        $sets = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($fila)));
        $pdo->prepare("UPDATE gastos SET $sets, actualizado_en = NOW() WHERE id = ?")->execute([...array_values($fila), $id]);
        if ($comprobante && $antes['comprobante']) finBorrarComprobante($antes['comprobante']);
        registrarAuditoria('gastos', $id, 'UPDATE', array_intersect_key($antes, $fila), $fila, $concepto);
    } else {
        [$uid, $unombre] = finUsuario();
        $fila += ['creado_por' => $uid, 'creado_por_nombre' => $unombre];
        $cols = implode(', ', array_map(fn($k) => "`$k`", array_keys($fila)));
        $pdo->prepare("INSERT INTO gastos ($cols) VALUES (" . implode(', ', array_fill(0, count($fila), '?')) . ')')->execute(array_values($fila));
        $id = (int)$pdo->lastInsertId();
        registrarAuditoria('gastos', $id, 'CREATE', null, $fila, $concepto);
    }
    return finGastoFila($pdo, $id);
}

function finCancelarGasto(PDO $pdo, int $id, string $motivo): array {
    finExigir(finPuedeCancelar(), 'Solo el administrador puede cancelar gastos.');
    $motivo = trim($motivo);
    if ($motivo === '') throw new FinanzasException('Escribe el motivo de la cancelación.');
    $g = finGastoFila($pdo, $id);
    if (!$g) throw new FinanzasException('El gasto no existe.', 404);
    if ((int)$g['cancelado'] === 1) throw new FinanzasException('El gasto ya está cancelado.');
    $pdo->prepare('UPDATE gastos SET cancelado = 1, motivo_cancelacion = ?, actualizado_en = NOW() WHERE id = ?')->execute([mb_substr($motivo, 0, 255), $id]);
    registrarAuditoria('gastos', $id, 'CANCELAR', ['cancelado' => 0], ['cancelado' => 1, 'motivo' => $motivo], $g['concepto']);
    return finGastoFila($pdo, $id);
}

/** $f: desde, hasta, categoria, buscar, incluir_cancelados. */
function finListarGastos(PDO $pdo, array $f): array {
    $w = ['1 = 1'];
    $p = [];
    if (empty($f['incluir_cancelados'])) $w[] = 'cancelado = 0';
    if (!empty($f['desde'])) { $w[] = 'fecha >= ?'; $p[] = finFecha($f['desde']); }
    if (!empty($f['hasta'])) { $w[] = 'fecha <= ?'; $p[] = finFecha($f['hasta']); }
    if (!empty($f['categoria'])) { $w[] = 'categoria = ?'; $p[] = $f['categoria']; }
    if (!empty($f['buscar'])) {
        $w[] = '(concepto LIKE ? OR proveedor LIKE ? OR referencia LIKE ? OR incidencia_folio LIKE ?)';
        array_push($p, ...array_fill(0, 4, '%' . $f['buscar'] . '%'));
    }
    $st = $pdo->prepare('SELECT * FROM gastos WHERE ' . implode(' AND ', $w) . ' ORDER BY fecha DESC, id DESC LIMIT 500');
    $st->execute($p);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** Categorías sugeridas: las de siempre más las que la empresa ya usó. */
function finCategoriasGasto(PDO $pdo): array {
    $usadas = $pdo->query('SELECT DISTINCT categoria FROM gastos ORDER BY categoria')->fetchAll(PDO::FETCH_COLUMN);
    return array_values(array_unique(array_merge(FIN_CATEGORIAS_GASTO, $usadas)));
}

// ---------------------------------------------------------------- tablero

function finResumen(PDO $pdo): array {
    $hoy = date('Y-m-d');
    $mes = date('Y-m-01');
    $abiertas = "estatus IN ('pendiente', 'parcial')";
    $uno = function (string $sql, array $p = []) use ($pdo) {
        $st = $pdo->prepare($sql);
        $st->execute($p);
        return finR($st->fetchColumn());
    };
    $r = [
        'por_cobrar' => $uno("SELECT COALESCE(SUM(total - cobrado), 0) FROM cuentas_cobrar WHERE $abiertas"),
        'vencido' => $uno("SELECT COALESCE(SUM(total - cobrado), 0) FROM cuentas_cobrar WHERE $abiertas AND vencimiento < ?", [$hoy]),
        'por_vencer_7' => $uno("SELECT COALESCE(SUM(total - cobrado), 0) FROM cuentas_cobrar WHERE $abiertas AND vencimiento BETWEEN ? AND ?", [$hoy, date('Y-m-d', strtotime('+7 days'))]),
        'facturado_mes' => $uno("SELECT COALESCE(SUM(total), 0) FROM cuentas_cobrar WHERE estatus <> 'cancelada' AND fecha >= ?", [$mes]),
        'cobrado_mes' => $uno('SELECT COALESCE(SUM(monto), 0) FROM cobros WHERE cancelado = 0' . finSoloCobros($pdo) . ' AND fecha >= ?', [$mes]),
        'gastos_mes' => $uno('SELECT COALESCE(SUM(monto), 0) FROM gastos WHERE cancelado = 0 AND fecha >= ?', [$mes]),
    ];
    $r['flujo_mes'] = finR($r['cobrado_mes'] - $r['gastos_mes']);

    // Antigüedad del saldo según días de atraso
    $st = $pdo->prepare("SELECT
            COALESCE(SUM(CASE WHEN vencimiento >= ? THEN total - cobrado END), 0) AS al_corriente,
            COALESCE(SUM(CASE WHEN DATEDIFF(?, vencimiento) BETWEEN 1 AND 30 THEN total - cobrado END), 0) AS d1_30,
            COALESCE(SUM(CASE WHEN DATEDIFF(?, vencimiento) BETWEEN 31 AND 60 THEN total - cobrado END), 0) AS d31_60,
            COALESCE(SUM(CASE WHEN DATEDIFF(?, vencimiento) BETWEEN 61 AND 90 THEN total - cobrado END), 0) AS d61_90,
            COALESCE(SUM(CASE WHEN DATEDIFF(?, vencimiento) > 90 THEN total - cobrado END), 0) AS mas_90
        FROM cuentas_cobrar WHERE $abiertas");
    $st->execute([$hoy, $hoy, $hoy, $hoy, $hoy]);
    $r['antiguedad'] = array_map('finR', $st->fetch(PDO::FETCH_ASSOC));

    $st = $pdo->prepare("SELECT cliente_id, cliente_nombre, SUM(total - cobrado) AS saldo,
                                SUM(CASE WHEN vencimiento < ? THEN total - cobrado ELSE 0 END) AS vencido, COUNT(*) AS cuentas
                         FROM cuentas_cobrar WHERE $abiertas
                         GROUP BY cliente_id, cliente_nombre ORDER BY saldo DESC LIMIT 5");
    $st->execute([$hoy]);
    $r['deudores'] = $st->fetchAll(PDO::FETCH_ASSOC);

    // Últimos 6 meses: cobrado contra gastos
    $desde = date('Y-m-01', strtotime('-5 months', strtotime($mes)));
    $meses = [];
    for ($i = 0; $i < 6; $i++) $meses[date('Y-m', strtotime("+$i months", strtotime($desde)))] = ['cobrado' => 0.0, 'gastos' => 0.0];
    foreach ([['cobros', 'cobrado'], ['gastos', 'gastos']] as [$tabla, $clave]) {
        $st = $pdo->prepare("SELECT DATE_FORMAT(fecha, '%Y-%m') AS m, SUM(monto) AS s FROM $tabla WHERE cancelado = 0" . ($tabla === 'cobros' ? finSoloCobros($pdo) : '') . " AND fecha >= ? GROUP BY m");
        $st->execute([$desde]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $f) if (isset($meses[$f['m']])) $meses[$f['m']][$clave] = finR($f['s']);
    }
    $r['meses'] = $meses;
    $r['pendientes'] = finPendientesDeRegistrar($pdo, 8);
    $r['pendientes_total'] = count(finPendientesDeRegistrar($pdo, 1000));
    return $r;
}

// ---------------------------------------------------------------- rentabilidad

/**
 * Rentabilidad por orden de servicio (fecha de la orden en el periodo):
 * ingreso = cuentas vigentes sin IVA (de la orden o de sus cotizaciones),
 * costo = refacciones al costo del momento en que se usaron + gastos ligados sin IVA.
 */
function finRentabilidad(PDO $pdo, string $desde, string $hasta): array {
    $desde = finFecha($desde); $hasta = finFecha($hasta);
    $st = $pdo->prepare('SELECT id, numero_incidente, cliente, fecha, estatus, tecnico FROM incidencias WHERE fecha BETWEEN ? AND ? ORDER BY fecha DESC, id DESC LIMIT 2000');
    $st->execute([$desde, $hasta]);
    $ordenes = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$ordenes) return ['ordenes' => [], 'clientes' => [], 'totales' => ['ingreso' => 0, 'refacciones' => 0, 'gastos' => 0, 'utilidad' => 0, 'margen_pct' => null]];
    $ids = array_column($ordenes, 'id');
    $in = implode(',', array_fill(0, count($ids), '?'));
    $mapa = function (string $sql) use ($pdo, $ids) {
        $st = $pdo->prepare($sql);
        $st->execute($ids);
        $m = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $f) $m[(int)$f['id']] = ($m[(int)$f['id']] ?? 0) + (float)$f['s'];
        return $m;
    };
    // D-48: una nota de crédito reduce el ingreso (su parte sin IVA, en proporción al total)
    $neto = fn(string $k) => finCreditoDisponible($pdo)
        ? "$k.subtotal - COALESCE((SELECT SUM(b.monto) FROM cobros b WHERE b.cuenta_id = $k.id AND b.tipo = 'nota_credito' AND b.cancelado = 0), 0) * $k.subtotal / NULLIF($k.total, 0)"
        : "$k.subtotal";
    $ingDirecto = $mapa("SELECT k.origen_id AS id, SUM(" . $neto('k') . ") AS s FROM cuentas_cobrar k
                         WHERE k.origen_tipo = 'incidencia' AND k.estatus <> 'cancelada' AND k.origen_id IN ($in) GROUP BY k.origen_id");
    $ingCot = $mapa("SELECT c.incidencia_id AS id, SUM(" . $neto('k') . ") AS s FROM cuentas_cobrar k
                     JOIN cotizaciones c ON c.id = k.origen_id
                     WHERE k.origen_tipo = 'cotizacion' AND k.estatus <> 'cancelada' AND c.incidencia_id IN ($in) GROUP BY c.incidencia_id");
    // H-07 · D-55: la orden también se cobra por la venta registrada desde su cotización
    $ingVenta = $mapa("SELECT x.incidencia_id AS id, SUM(" . $neto('k') . ") AS s FROM cuentas_cobrar k
                       JOIN (SELECT DISTINCT cv.venta_id, c.incidencia_id FROM cotizacion_ventas cv JOIN cotizaciones c ON c.id = cv.cotizacion_id
                             WHERE cv.venta_id IS NOT NULL AND c.incidencia_id IN ($in)) x ON x.venta_id = k.origen_id
                       WHERE k.origen_tipo = 'venta' AND k.estatus <> 'cancelada' GROUP BY x.incidencia_id");
    $refacciones = $mapa("SELECT m.referencia_id AS id, SUM(-m.cantidad * COALESCE(m.costo_unitario, a.costo_unitario, 0)) AS s
                          FROM inventario_movimientos m JOIN inventario_articulos a ON a.id = m.articulo_id
                          WHERE m.referencia_tipo = 'incidencia' AND m.referencia_id IN ($in) GROUP BY m.referencia_id");
    // L2-2: si la orden se cobra por la venta de su cotización, el costo de lo vendido también es de la orden
    $costoVenta = $mapa("SELECT x.incidencia_id AS id, SUM(-m.cantidad * COALESCE(m.costo_unitario, a.costo_unitario, 0)) AS s
                         FROM inventario_movimientos m JOIN inventario_articulos a ON a.id = m.articulo_id
                         JOIN (SELECT DISTINCT cv.venta_id, c.incidencia_id FROM cotizacion_ventas cv JOIN cotizaciones c ON c.id = cv.cotizacion_id
                               WHERE cv.venta_id IS NOT NULL AND c.incidencia_id IN ($in)) x ON x.venta_id = m.referencia_id
                         WHERE m.referencia_tipo = 'venta' GROUP BY x.incidencia_id");
    foreach ($costoVenta as $k => $v) $refacciones[$k] = ($refacciones[$k] ?? 0) + $v;
    $gastos = $mapa("SELECT incidencia_id AS id, SUM(monto - iva) AS s FROM gastos WHERE cancelado = 0 AND incidencia_id IN ($in) GROUP BY incidencia_id");

    $filas = [];
    $tot = ['ingreso' => 0.0, 'refacciones' => 0.0, 'gastos' => 0.0];
    $porCliente = [];
    foreach ($ordenes as $o) {
        $id = (int)$o['id'];
        $ing = finR(($ingDirecto[$id] ?? 0) + ($ingCot[$id] ?? 0) + ($ingVenta[$id] ?? 0));
        $ref = finR(max(0, $refacciones[$id] ?? 0));
        $gas = finR($gastos[$id] ?? 0);
        if ($ing <= 0 && $ref <= 0 && $gas <= 0) continue;
        $util = finR($ing - $ref - $gas);
        $filas[] = $o + ['ingreso' => $ing, 'refacciones' => $ref, 'gastos' => $gas, 'utilidad' => $util,
                         'margen_pct' => $ing > 0 ? round($util / $ing * 100, 1) : null, 'sin_ingreso' => $ing <= 0];
        $tot['ingreso'] += $ing; $tot['refacciones'] += $ref; $tot['gastos'] += $gas;
        $k = mb_strtoupper(trim((string)$o['cliente']));
        $porCliente[$k] ??= ['cliente' => $o['cliente'], 'ordenes' => 0, 'ingreso' => 0.0, 'costo' => 0.0];
        $porCliente[$k]['ordenes']++;
        $porCliente[$k]['ingreso'] += $ing;
        $porCliente[$k]['costo'] += $ref + $gas;
    }
    $clientes = array_map(function ($c) {
        $c['ingreso'] = finR($c['ingreso']); $c['costo'] = finR($c['costo']);
        $c['utilidad'] = finR($c['ingreso'] - $c['costo']);
        $c['margen_pct'] = $c['ingreso'] > 0 ? round($c['utilidad'] / $c['ingreso'] * 100, 1) : null;
        return $c;
    }, array_values($porCliente));
    usort($clientes, fn($a, $b) => $b['utilidad'] <=> $a['utilidad']);
    $tot = array_map('finR', $tot);
    $tot['utilidad'] = finR($tot['ingreso'] - $tot['refacciones'] - $tot['gastos']);
    $tot['margen_pct'] = $tot['ingreso'] > 0 ? round($tot['utilidad'] / $tot['ingreso'] * 100, 1) : null;
    return ['ordenes' => $filas, 'clientes' => $clientes, 'totales' => $tot];
}

// ---------------------------------------------------------------- estado de cuenta

function finEstadoCuenta(PDO $pdo, int $clienteId): array {
    $cliente = finCliente($pdo, $clienteId);
    if (!$cliente) throw new FinanzasException('El cliente no existe.', 404);
    $st = $pdo->prepare("SELECT * FROM cuentas_cobrar WHERE cliente_id = ? AND estatus IN ('pendiente', 'parcial') ORDER BY vencimiento, id");
    $st->execute([$clienteId]);
    $cuentas = array_map('finDecorar', $st->fetchAll(PDO::FETCH_ASSOC));
    $st = $pdo->prepare("SELECT b.fecha, b.monto, b.forma, b.referencia, k.folio, k.factura FROM cobros b JOIN cuentas_cobrar k ON k.id = b.cuenta_id
                         WHERE k.cliente_id = ? AND b.cancelado = 0" . finSoloCobros($pdo, 'b.') . " AND b.fecha >= ? ORDER BY b.fecha DESC, b.id DESC");
    $st->execute([$clienteId, date('Y-m-d', strtotime('-90 days'))]);
    $cobros = $st->fetchAll(PDO::FETCH_ASSOC);
    $notas = [];
    if (finCreditoDisponible($pdo)) {
        $st = $pdo->prepare("SELECT b.id, b.fecha, b.monto, b.referencia, b.notas AS motivo, b.cancelado, k.folio FROM cobros b JOIN cuentas_cobrar k ON k.id = b.cuenta_id
                             WHERE k.cliente_id = ? AND b.tipo = 'nota_credito' ORDER BY b.fecha DESC, b.id DESC LIMIT 100");
        $st->execute([$clienteId]);
        $notas = $st->fetchAll(PDO::FETCH_ASSOC);
    }
    $saldo = finR(array_sum(array_column($cuentas, 'saldo')));
    $vencido = finR(array_sum(array_map(fn($c) => $c['dias_vencida'] > 0 ? $c['saldo'] : 0, $cuentas)));
    return ['cliente' => $cliente, 'cuentas' => $cuentas, 'cobros' => $cobros, 'notas_credito' => $notas,
            'saldo' => $saldo, 'vencido' => $vencido, 'fecha' => date('Y-m-d'),
            'aviso_credito' => finAvisoCredito($pdo, $clienteId)];
}
