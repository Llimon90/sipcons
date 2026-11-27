<?php
// Lógica del módulo de cotizaciones (Fase 1). La usan backend/cotizaciones.php,
// backend/registro_ventas.php (ventas que vienen de una cotización) y tests/.
//
// Reglas principales:
//  - Cada partida sale del catálogo (inventario_articulos): su descripción,
//    precio y costo se COPIAN al cotizar. Cambios posteriores en el catálogo
//    no alteran cotizaciones existentes.
//  - Los importes se calculan y redondean SIEMPRE aquí (2 decimales, por
//    partida y luego suma). El navegador solo muestra una vista previa.
//  - Solo se edita en "borrador". Una enviada, vencida o rechazada se cambia
//    con una nueva versión (COT-0001-v2) y la anterior queda de consulta.
//  - Una cotización NUNCA mueve existencias. Al aprobarla deja "ventas por
//    registrar" (una por modelo de equipo aceptado); la venta, al registrarse
//    en Ventas, es la que descuenta el inventario.
//  - Cotización posterior al servicio: se arma con las refacciones que ya se
//    registraron en la incidencia. Esas partidas nacen "ya instaladas"
//    (cargada_incidencia = 1) y aprobarlas no vuelve a descontar inventario.
//
// Requiere $pdo (config/database.php), sesión (config/auth.php) y permisos.
require_once __DIR__ . '/../../auth/audit.php';
require_once __DIR__ . '/../../auth/permisos.php';
require_once __DIR__ . '/inventario_helpers.php';

const COT_ESTATUS = ['borrador', 'enviada', 'aprobada', 'rechazada', 'vencida', 'convertida'];
const COT_TRANSICIONES = [
    'borrador'   => ['enviada'],
    'enviada'    => ['aprobada', 'rechazada', 'vencida'],
    'aprobada'   => ['convertida'],
    'vencida'    => [],
    'rechazada'  => [],
    'convertida' => [],
];
// Estatus desde los que se puede sacar una nueva versión
const COT_ESTATUS_VERSIONABLES = ['enviada', 'vencida', 'rechazada'];
const COT_MONEDAS = ['MXN', 'USD'];
const COT_TASAS_IVA = [0.0, 0.08, 0.16];
const COT_MARGEN_MINIMO = 15.0;   // % por debajo del cual se avisa
// Quién ve costos y márgenes (nunca aparecen en el PDF)
const COT_ROLES_MARGEN = ['Administrador', 'Administrativo', ROL_ACCESO_TOTAL];
const COT_CONFIG_DEFECTO = [
    'prefijo'        => 'COT',
    'vigencia_dias'  => 15,
    'iva'            => 0.16,
    'descuento_max'  => 10.0,
    // Tipo de cambio de referencia de la empresa (MXN por USD). 0 = sin
    // configurar: las cotizaciones en USD piden el tc a mano y la valuación de
    // inventario en pesos no convierte los artículos en USD.
    'tipo_cambio'    => 0.0,
    'terminos'       => "Tiempo de entrega: a convenir una vez aprobada la cotización.\nForma de pago: 50% de anticipo y 50% contra entrega.\nGarantía: la del fabricante en equipos; 30 días en mano de obra.\nPrecios sujetos a cambio sin previo aviso después de la vigencia.",
];

class CotizacionException extends RuntimeException {
    public int $http;
    public string $motivo;
    public array $datos;
    public function __construct(string $mensaje, int $http = 400, string $motivo = 'invalido', array $datos = []) {
        parent::__construct($mensaje);
        $this->http = $http;
        $this->motivo = $motivo;
        $this->datos = $datos;
    }
}

// ---------------------------------------------------------------- configuración

/** Configuración de cotizaciones de la empresa (empresas.marca -> cotizaciones). */
function cotConfig(?array $empresa = null): array {
    $empresa = $empresa ?? (function_exists('empresaActual') ? empresaActual() : null);
    $c = array_replace(COT_CONFIG_DEFECTO, (array)($empresa['marca']['cotizaciones'] ?? []));
    $c['prefijo'] = cotLimpiarPrefijo((string)$c['prefijo']) ?: COT_CONFIG_DEFECTO['prefijo'];
    $c['vigencia_dias'] = max(1, min(365, (int)$c['vigencia_dias']));
    $c['iva'] = in_array((float)$c['iva'], COT_TASAS_IVA, true) ? (float)$c['iva'] : 0.16;
    $c['descuento_max'] = max(0, min(100, (float)$c['descuento_max']));
    $c['tipo_cambio'] = max(0.0, round((float)($c['tipo_cambio'] ?? 0), 4));
    $c['terminos'] = (string)$c['terminos'];
    return $c;
}

/** Tipo de cambio de referencia de la empresa (MXN por USD); 0 = sin configurar. */
function cotTipoCambioEmpresa(?array $empresa = null): float {
    return (float)cotConfig($empresa)['tipo_cambio'];
}

function cotLimpiarPrefijo(string $p): string {
    return substr(strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $p)), 0, 6);
}

/** Valida y normaliza la configuración que manda Mi empresa. */
function cotValidarConfig(array $d): array {
    $prefijo = cotLimpiarPrefijo((string)($d['prefijo'] ?? ''));
    if ($prefijo === '') throw new CotizacionException('El prefijo de folio de cotizaciones lleva de 1 a 6 letras o números.');
    $vigencia = (int)($d['vigencia_dias'] ?? 0);
    if ($vigencia < 1 || $vigencia > 365) throw new CotizacionException('La vigencia por defecto va de 1 a 365 días.');
    $iva = (float)($d['iva'] ?? -1);
    if (!in_array($iva, [0.08, 0.16], true)) throw new CotizacionException('El IVA por defecto es 16% u 8% (zona fronteriza).');
    $desc = round((float)($d['descuento_max'] ?? -1), 2);
    if ($desc < 0 || $desc > 100) throw new CotizacionException('El descuento máximo va de 0 a 100%.');
    $tc = round((float)($d['tipo_cambio'] ?? 0), 4);
    if ($tc < 0 || $tc > 100000) throw new CotizacionException('El tipo de cambio debe ser 0 (sin configurar) o un número positivo.');
    return [
        'prefijo' => $prefijo, 'vigencia_dias' => $vigencia, 'iva' => $iva, 'descuento_max' => $desc,
        'tipo_cambio' => $tc,
        'terminos' => mb_substr(trim((string)($d['terminos'] ?? '')), 0, 3000),
    ];
}

// ---------------------------------------------------------------- permisos

function cotUsuarioId(): int { return (int)($_SESSION['user_id'] ?? 0); }
function cotRol(): string { return (string)($_SESSION['rol'] ?? ''); }

/** Acceso completo al módulo (lista, enviar, aprobar, convertir...). */
function cotAccesoModulo(): bool { return tienePermiso('cotizaciones'); }

function cotVeMargen(): bool { return in_array(cotRol(), COT_ROLES_MARGEN, true); }

function cotPuedeEliminar(): bool { return esAdministracion(); }

/** El Administrador siempre; los demás si su usuario tiene la casilla. */
function cotPuedeAutorizarDescuentos(): bool {
    if (esAdministracion()) return true;
    global $pdo;
    $id = cotUsuarioId();
    if ($id <= 0) return false;
    try {
        $st = $pdo->prepare('SELECT puede_autorizar_descuentos FROM usuarios WHERE id = ?');
        $st->execute([$id]);
        return (int)$st->fetchColumn() === 1;
    } catch (Throwable $e) {
        return false; // sin la migración 007
    }
}

/**
 * Sin acceso al módulo (rol Técnico), solo se trabaja con los borradores que
 * uno mismo creó desde una incidencia.
 */
function cotPuedeVer(array $cot): bool {
    require_once __DIR__ . '/incidencias_helpers.php';
    if (cotAccesoModulo() && !soloOrdenesPropias()) return true;
    // Técnico al que se le dio el módulo de cotizaciones: solo las de órdenes suyas
    if (soloOrdenesPropias() && cotAccesoModulo()) {
        global $pdo;
        return !empty($cot['incidencia_id']) && ordenVisiblePorId($pdo, (int)$cot['incidencia_id']) !== null;
    }
    // Sin el módulo (Técnico): solo las que creó él, desde una orden suya (RP-17, D-9)
    global $pdo;
    require_once __DIR__ . '/incidencias_helpers.php';
    return tienePermiso('incidencias') && !empty($cot['incidencia_id'])
        && (int)$cot['creado_por'] === cotUsuarioId() && ordenVisiblePorId($pdo, (int)$cot['incidencia_id']) !== null;
}

function cotPuedeEditar(array $cot): bool {
    if ($cot['estatus'] !== 'borrador' || !empty($cot['reemplazada_por'])) return false;
    if (!cotPuedeVer($cot)) return false;  // D-9: orden reasignada = ya no es suya
    if (cotAccesoModulo()) return true;
    return tienePermiso('incidencias') && !empty($cot['incidencia_id']) && (int)$cot['creado_por'] === cotUsuarioId();
}

function cotExigir(bool $condicion, string $mensaje = 'Tu rol no tiene permiso para esta acción.'): void {
    if (!$condicion) throw new CotizacionException($mensaje, 403, 'sin_permiso');
}

// ---------------------------------------------------------------- cálculo

function cotR($x): float {
    return round((float)$x + 0.0, 2);
}

/**
 * Convierte un precio de la moneda del artículo a la moneda de la cotización.
 * tipo_cambio es MXN por USD (p.ej. 17 → 1 USD = 17 MXN).
 * Cuando las monedas son iguales, o el tipo_cambio es inválido (≤0), devuelve el
 * precio sin tocar. Para artículos MXN en cotizaciones MXN/USD o artículos USD en
 * cotizaciones USD/MXN, usa MXN como moneda intermedia.
 */
function cotConvertirPrecio(float $precio, string $monedaOrigen, string $monedaDest, float $tc): float {
    if ($monedaOrigen === $monedaDest || $tc <= 0) return cotR($precio);
    $enMXN = $monedaOrigen === 'MXN' ? $precio : $precio * $tc;
    return $monedaDest === 'MXN' ? cotR($enMXN) : cotR($enMXN / $tc);
}

/** Tipo de partida según la categoría del catálogo. */
function cotTipoDeCategoria(string $categoria): string {
    return match ($categoria) {
        'Equipo' => 'equipo',
        'Servicio' => 'servicio',
        'Mano de obra' => 'mano_obra',
        default => 'refaccion',
    };
}

/**
 * Importes de una partida. Con precios que incluyen IVA se respeta el total
 * capturado (cantidad × precio − descuento) al centavo y de ahí se desglosa.
 * Devuelve importe (sin IVA, ya con descuento), descuento_importe (sin IVA), iva.
 */
function cotCalcularPartida(array $p, bool $incluyenIva): array {
    $cant = max(0, cotR($p['cantidad'] ?? 0));
    $precio = max(0, cotR($p['precio_unitario'] ?? 0));
    $pct = max(0, min(100, cotR($p['descuento_pct'] ?? 0)));
    $exento = !empty($p['es_exento']);
    $tasa = $exento ? 0.0 : (float)($p['tasa_iva'] ?? 0.16);
    if (!in_array($tasa, COT_TASAS_IVA, true)) {
        throw new CotizacionException('Tasa de IVA no válida: usa 0%, 8% o 16%.');
    }

    if ($incluyenIva) {
        $bruto = cotR($cant * $precio);
        $total = cotR($bruto * (1 - $pct / 100));
        $importe = $tasa > 0 ? cotR($total / (1 + $tasa)) : $total;
        $iva = cotR($total - $importe);
        $descConIva = cotR($bruto - $total);
        $descuento = $tasa > 0 ? cotR($descConIva / (1 + $tasa)) : $descConIva;
    } else {
        $bruto = cotR($cant * $precio);
        $descuento = cotR($bruto * $pct / 100);
        $importe = cotR($bruto - $descuento);
        $iva = cotR($importe * $tasa);
    }
    return [
        'cantidad' => $cant, 'precio_unitario' => $precio, 'descuento_pct' => $pct,
        'tasa_iva' => $tasa, 'es_exento' => $exento ? 1 : 0,
        'importe' => $importe, 'descuento_importe' => $descuento, 'iva' => $iva,
    ];
}

/**
 * Totales de la cotización a partir de partidas ya calculadas (o crudas).
 * subtotal = antes de descuento y sin IVA; total = subtotal − descuento + IVA.
 */
function cotCalcularTotales(array $partidas, bool $incluyenIva): array {
    $subtotal = $descuento = $iva = $base = $costo = 0.0;
    $porTasa = [];
    $calculadas = [];
    foreach ($partidas as $p) {
        $c = array_replace($p, cotCalcularPartida($p, $incluyenIva));
        $calculadas[] = $c;
        $subtotal += $c['importe'] + $c['descuento_importe'];
        $descuento += $c['descuento_importe'];
        $iva += $c['iva'];
        $base += $c['importe'];
        $costo += cotR((float)($c['costo_unitario'] ?? 0) * $c['cantidad']);
        $clave = $c['es_exento'] ? 'exento' : number_format($c['tasa_iva'], 2);
        $porTasa[$clave] ??= ['etiqueta' => $c['es_exento'] ? 'Exento' : 'IVA ' . rtrim(rtrim(number_format($c['tasa_iva'] * 100, 2), '0'), '.') . '%',
                              'tasa' => $c['tasa_iva'], 'base' => 0.0, 'iva' => 0.0];
        $porTasa[$clave]['base'] = cotR($porTasa[$clave]['base'] + $c['importe']);
        $porTasa[$clave]['iva'] = cotR($porTasa[$clave]['iva'] + $c['iva']);
    }
    krsort($porTasa);
    $margen = cotR($base - $costo);
    return [
        'partidas' => $calculadas,
        'subtotal' => cotR($subtotal),
        'descuento_total' => cotR($descuento),
        'iva_total' => cotR($iva),
        'total' => cotR($base + $iva),
        'iva_por_tasa' => array_values($porTasa),
        'costo_total' => cotR($costo),
        'margen' => $margen,
        'margen_pct' => $base > 0 ? round($margen / $base * 100, 1) : null,
    ];
}

/** Partidas o total que rebasan el descuento máximo (vacío = dentro del límite). */
function cotDescuentosExcedidos(array $totales, float $maximo): array {
    $excedidos = [];
    foreach ($totales['partidas'] as $i => $p) {
        if ($p['descuento_pct'] > $maximo + 0.001) {
            $excedidos[] = sprintf('Partida %d (%s): %s%%', $i + 1, $p['descripcion'] ?? '', rtrim(rtrim(number_format($p['descuento_pct'], 2), '0'), '.'));
        }
    }
    if ($totales['subtotal'] > 0) {
        $global = $totales['descuento_total'] / $totales['subtotal'] * 100;
        if ($global > $maximo + 0.001) $excedidos[] = sprintf('Descuento total: %.1f%%', $global);
    }
    return $excedidos;
}

// ---------------------------------------------------------------- lectura

function cotFila(PDO $pdo, int $id, bool $bloquear = false): ?array {
    $st = $pdo->prepare('SELECT * FROM cotizaciones WHERE id = ?' . ($bloquear ? ' FOR UPDATE' : ''));
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function cotPartidas(PDO $pdo, int $id): array {
    $st = $pdo->prepare('SELECT * FROM cotizacion_partidas WHERE cotizacion_id = ? ORDER BY orden, id');
    $st->execute([$id]);
    $partidas = $st->fetchAll(PDO::FETCH_ASSOC);
    // D-43: lo que incluye cada servicio (descripción del catálogo); no entra en la huella de versión
    $ids = array_values(array_unique(array_filter(array_map(fn($p) => $p['tipo'] === 'servicio' ? (int)$p['articulo_id'] : 0, $partidas))));
    $incluye = [];
    if ($ids) {
        $st = $pdo->prepare('SELECT id, descripcion FROM inventario_articulos WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
        $st->execute($ids);
        $incluye = array_column($st->fetchAll(PDO::FETCH_ASSOC), 'descripcion', 'id');
    }
    foreach ($partidas as &$p) {
        // L-9: una cotización enviada muestra lo que incluía el servicio el día que se envió
        $texto = array_key_exists('incluye_congelado', $p) && $p['incluye_congelado'] !== null
            ? $p['incluye_congelado'] : ($incluye[(int)$p['articulo_id']] ?? '');
        $p['incluye'] = $p['tipo'] === 'servicio' ? (trim((string)$texto) ?: null) : null;
        unset($p['incluye_congelado']);
    }
    return $partidas;
}

/** L-9: al enviar, congela lo que incluye cada servicio (migración 022). */
function cotCongelarIncluye(PDO $pdo, int $id): void {
    static $hay = [];
    $clave = EmpresaContexto::$id ?? 0;
    if (!isset($hay[$clave])) {
        try { $hay[$clave] = (bool)$pdo->query("SHOW COLUMNS FROM cotizacion_partidas LIKE 'incluye_congelado'")->fetch(); } catch (Throwable $e) { $hay[$clave] = false; }
    }
    if (!$hay[$clave]) return;
    $pdo->prepare("UPDATE cotizacion_partidas p JOIN inventario_articulos a ON a.id = p.articulo_id
                   SET p.incluye_congelado = COALESCE(a.descripcion, '')
                   WHERE p.cotizacion_id = ? AND p.tipo = 'servicio' AND p.incluye_congelado IS NULL")->execute([$id]);
}

/** Huella del registro (cabecera + partidas) para detectar ediciones concurrentes. */
function cotVersionHuella(array $cot, array $partidas): string {
    $normal = fn(array $f) => array_map(fn($v) => $v === null ? null : (string)$v, $f);
    $cab = $normal($cot);
    ksort($cab);
    $partidas = array_map(fn($p) => array_diff_key($p, ['incluye' => 1]), $partidas);  // dato del catálogo, no de la cotización
    return hash('sha256', json_encode([$cab, array_map($normal, $partidas)], JSON_UNESCAPED_UNICODE));
}

/** Cotización completa para la interfaz (sin costos si el usuario no los ve). */
function cotObtener(PDO $pdo, int $id): ?array {
    $cot = cotFila($pdo, $id);
    if (!$cot) return null;
    $partidas = cotPartidas($pdo, $id);
    $huella = cotVersionHuella($cot, $partidas);
    $totales = cotCalcularTotales($partidas, (bool)$cot['precios_incluyen_iva']);

    $st = $pdo->prepare('SELECT cv.*, v.folio AS venta_folio FROM cotizacion_ventas cv LEFT JOIN ventas v ON v.id = cv.venta_id WHERE cv.cotizacion_id = ? ORDER BY cv.id');
    $st->execute([$id]);
    $ventas = $st->fetchAll(PDO::FETCH_ASSOC);

    $serie = $pdo->prepare('SELECT id, folio, version, estatus, total, creado_en FROM cotizaciones WHERE folio_base = ? ORDER BY version');
    $serie->execute([$cot['folio_base']]);

    $incidencia = null;
    if ($cot['incidencia_id']) {
        $st = $pdo->prepare('SELECT id, numero_incidente, estatus, falla FROM incidencias WHERE id = ?');
        $st->execute([$cot['incidencia_id']]);
        $incidencia = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    $cot['partidas'] = $partidas;
    $cot['iva_por_tasa'] = $totales['iva_por_tasa'];
    $cot['ventas'] = $ventas;
    $cot['serie'] = $serie->fetchAll(PDO::FETCH_ASSOC);
    $cot['incidencia'] = $incidencia;
    $cot['version_huella'] = $huella;
    // D-48: aviso si el saldo del cliente más esta cotización rebasa su límite (solo con Finanzas)
    $cot['aviso_credito'] = null;
    if ($cot['cliente_id'] && in_array($cot['estatus'], ['borrador', 'enviada'], true) && function_exists('tienePermiso') && tienePermiso('finanzas')) {
        require_once __DIR__ . '/finanzas_helpers.php';
        $cot['aviso_credito'] = finAvisoCredito($pdo, (int)$cot['cliente_id'], (float)$cot['total'] * ($cot['moneda'] === 'MXN' ? 1.0 : (float)$cot['tipo_cambio']));  // L-15: en pesos
    }
    if (cotVeMargen()) {
        $cot['costo_total'] = $totales['costo_total'];
        $cot['margen'] = $totales['margen'];
        $cot['margen_pct'] = $totales['margen_pct'];
    } else {
        $cot = cotSinCostos($cot);
    }
    $cot['permisos'] = [
        'editar' => cotPuedeEditar($cot),
        'gestionar' => cotAccesoModulo(),
        'eliminar' => cotPuedeEliminar() && $cot['estatus'] === 'borrador',
        've_margen' => cotVeMargen(),
    ];
    return $cot;
}

function cotSinCostos(array $cot): array {
    unset($cot['costo_total'], $cot['margen'], $cot['margen_pct']);
    foreach ($cot['partidas'] ?? [] as $i => $p) unset($cot['partidas'][$i]['costo_unitario']);
    return $cot;
}

// ---------------------------------------------------------------- folios y vencidas

/** Siguiente folio de la empresa (COT-0001). Llamar dentro de una transacción. */
function cotSiguienteFolio(PDO $pdo, string $prefijo): string {
    $ultimo = $pdo->query('SELECT folio_base FROM cotizaciones WHERE version = 1 ORDER BY id DESC LIMIT 1 FOR UPDATE')->fetchColumn();
    $n = ($ultimo && preg_match('/(\d+)\s*$/', (string)$ultimo, $m)) ? (int)$m[1] : 0;
    do {
        $n++;
        $folio = $prefijo . '-' . str_pad((string)$n, 4, '0', STR_PAD_LEFT);
        $existe = $pdo->prepare('SELECT COUNT(*) FROM cotizaciones WHERE folio_base = ?');
        $existe->execute([$folio]);
    } while ((int)$existe->fetchColumn() > 0); // por si cambió el prefijo y luego volvió
    return $folio;
}

function cotFolioVersion(string $base, int $version): string {
    return $version > 1 ? "$base-v$version" : $base;
}

/** Registro del historial hecho por el sistema (sin usuario en sesión). */
function cotAuditarSistema(PDO $pdo, int $id, string $folio, string $accion, array $antes, array $despues): void {
    $pdo->prepare("INSERT INTO auditoria (usuario_nombre, usuario_rol, accion, tabla, registro_id, registro_folio, datos_anteriores, datos_nuevos)
                   VALUES ('Sistema', 'Sistema', ?, 'cotizaciones', ?, ?, ?, ?)")
        ->execute([$accion, (string)$id, $folio, json_encode($antes, JSON_UNESCAPED_UNICODE), json_encode($despues, JSON_UNESCAPED_UNICODE)]);
}

/** Enviadas con vigencia pasada → vencidas. Se llama al listar y al consultar. */
function cotMarcarVencidas(PDO $pdo): int {
    if (function_exists('empresaAdmiteEscrituras') && !empresaAdmiteEscrituras()) return 0;  // L-20
    $ids = $pdo->query("SELECT id, folio FROM cotizaciones WHERE estatus = 'enviada' AND vigencia_hasta < CURDATE() AND reemplazada_por IS NULL")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($ids as $c) {
        $act = $pdo->prepare("UPDATE cotizaciones SET estatus = 'vencida', actualizado_en = NOW() WHERE id = ? AND estatus = 'enviada'");
        $act->execute([$c['id']]);
        if ($act->rowCount()) cotAuditarSistema($pdo, (int)$c['id'], $c['folio'], 'ESTATUS', ['estatus' => 'enviada'], ['estatus' => 'vencida']);
    }
    return count($ids);
}

// ---------------------------------------------------------------- catálogo

function cotArticulo(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare('SELECT * FROM inventario_articulos WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

// ---------------------------------------------------------------- guardar

/** Valida el encabezado que llega de la interfaz. */
function cotValidarEncabezado(PDO $pdo, array $d, array $cfg): array {
    $clienteId = (int)($d['cliente_id'] ?? 0);
    $st = $pdo->prepare('SELECT id, nombre FROM clientes WHERE id = ?');
    $st->execute([$clienteId]);
    $cliente = $st->fetch(PDO::FETCH_ASSOC);
    if (!$cliente) throw new CotizacionException('Selecciona un cliente del catálogo.', 400, 'faltan_datos');

    $contactoId = (int)($d['contacto_id'] ?? 0) ?: null;
    $contactoNombre = null;
    if ($contactoId) {
        $st = $pdo->prepare('SELECT nombre FROM cliente_contactos WHERE id = ? AND cliente_id = ?');
        $st->execute([$contactoId, $clienteId]);
        $contactoNombre = $st->fetchColumn();
        if ($contactoNombre === false) throw new CotizacionException('El contacto no pertenece a ese cliente.');
    }

    $moneda = strtoupper((string)($d['moneda'] ?? 'MXN'));
    if (!in_array($moneda, COT_MONEDAS, true)) throw new CotizacionException('Moneda no válida.');
    // Para cotizaciones MXN, tipo_cambio es opcional y sirve de tasa de referencia para artículos
    // en USD; si no se indica, queda en 1.0 (sin conversión automática).
    if ($moneda === 'MXN') {
        $tc = (float)($d['tipo_cambio'] ?? 0) > 0 ? round((float)$d['tipo_cambio'], 4) : 1.0;
    } else {
        $tc = round((float)($d['tipo_cambio'] ?? 0), 4);
        if ($tc <= 0) throw new CotizacionException('Captura el tipo de cambio de la cotización en dólares.', 400, 'faltan_datos');
    }

    $fecha = (string)($d['fecha'] ?? date('Y-m-d'));
    $vigencia = (string)($d['vigencia_hasta'] ?? '');
    foreach ([$fecha, $vigencia] as $f) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $f) || !strtotime($f)) throw new CotizacionException('Fecha no válida.', 400, 'faltan_datos');
    }
    if ($vigencia < $fecha) throw new CotizacionException('La vigencia no puede ser anterior a la fecha de la cotización.');

    $texto = fn($k, $max) => ($v = trim((string)($d[$k] ?? ''))) === '' ? null : mb_substr($v, 0, $max);
    return [
        'cliente_id' => $clienteId, 'cliente_nombre' => $cliente['nombre'],
        'sucursal' => $texto('sucursal', 255),
        'contacto_id' => $contactoId, 'contacto_nombre' => $contactoNombre ?: null,
        'fecha' => $fecha, 'vigencia_hasta' => $vigencia,
        'moneda' => $moneda, 'tipo_cambio' => $tc,
        'precios_incluyen_iva' => !empty($d['precios_incluyen_iva']) ? 1 : 0,
        'condiciones' => $texto('condiciones', 3000),
        'notas_cliente' => $texto('notas_cliente', 3000),
        'notas_internas' => $texto('notas_internas', 3000),
    ];
}

/**
 * Normaliza las partidas que llegan. Las existentes conservan lo congelado
 * (artículo, costo, tipo); las nuevas toman descripción y costo del catálogo.
 * El costo NUNCA viene del navegador.
 */
function cotPrepararPartidas(PDO $pdo, array $entrada, array $existentes, float $tipoCambio, float $ivaDefecto, array $permitirInactivos = [], string $monedaCot = 'MXN'): array {
    $porId = [];
    foreach ($existentes as $e) $porId[(int)$e['id']] = $e;
    $salida = [];
    foreach (array_values($entrada) as $i => $p) {
        $id = (int)($p['id'] ?? 0);
        if ($id) {
            if (!isset($porId[$id])) throw new CotizacionException('Una partida no pertenece a esta cotización. Recarga la página.');
            $base = $porId[$id];
        } else {
            $art = cotArticulo($pdo, (int)($p['articulo_id'] ?? 0));
            // L-8: lo ya aplicado en la orden (solo desde el servidor) se cotiza aunque se haya desactivado
            if (!$art || (!(int)$art['activo'] && !in_array((int)$art['id'], $permitirInactivos, true))) {
                throw new CotizacionException('Una partida no corresponde a un artículo activo del catálogo.');
            }
            $monedaArt = invTieneMoneda($pdo) ? (string)($art['moneda'] ?? 'MXN') : 'MXN';
            $artTasaIva = invTieneTasaIva($pdo) ? (float)($art['tasa_iva'] ?? $ivaDefecto) : $ivaDefecto;
            $base = [
                'id' => null, 'articulo_id' => (int)$art['id'], 'sku' => $art['sku'],
                'tipo' => cotTipoDeCategoria($art['categoria']),
                'descripcion' => $art['nombre'], 'marca' => $art['marca'], 'modelo' => $art['modelo'],
                'unidad' => $art['unidad'] ?: 'pza',
                'costo_unitario' => cotConvertirPrecio((float)$art['costo_unitario'], $monedaArt, $monedaCot, $tipoCambio),
                'precio_unitario' => cotConvertirPrecio((float)$art['precio_venta'], $monedaArt, $monedaCot, $tipoCambio),
                'tasa_iva' => $artTasaIva, 'es_exento' => 0,
            ];
        }
        $cantidad = cotR($p['cantidad'] ?? $base['cantidad'] ?? 1);
        if ($cantidad <= 0) throw new CotizacionException('La cantidad de cada partida debe ser mayor a cero.');
        $descripcion = trim((string)($p['descripcion'] ?? '')) ?: $base['descripcion'];
        $salida[] = [
            'id' => $base['id'] ? (int)$base['id'] : null,
            'orden' => $i + 1,
            'tipo' => $base['tipo'],
            'articulo_id' => $base['articulo_id'],
            'sku' => $base['sku'],
            'descripcion' => mb_substr($descripcion, 0, 255),
            'marca' => $base['marca'], 'modelo' => $base['modelo'], 'unidad' => $base['unidad'],
            'cantidad' => $cantidad,
            'precio_unitario' => array_key_exists('precio_unitario', $p) ? cotR($p['precio_unitario']) : cotR($base['precio_unitario']),
            'descuento_pct' => cotR($p['descuento_pct'] ?? $base['descuento_pct'] ?? 0),
            'tasa_iva' => array_key_exists('tasa_iva', $p) ? (float)$p['tasa_iva'] : (float)$base['tasa_iva'],
            'es_exento' => !empty($p['es_exento'] ?? $base['es_exento']) ? 1 : 0,
            'costo_unitario' => cotR($base['costo_unitario']),
        ];
    }
    return $salida;
}

const COT_CAMPOS_PARTIDA = ['orden', 'tipo', 'articulo_id', 'sku', 'descripcion', 'marca', 'modelo', 'unidad', 'cantidad',
    'precio_unitario', 'descuento_pct', 'tasa_iva', 'es_exento', 'costo_unitario', 'importe', 'descuento_importe', 'iva'];

/** Resumen para el historial. */
function cotResumen(array $cot, ?array $partidas = null): array {
    $r = array_intersect_key($cot, array_flip(['folio', 'estatus', 'cliente_nombre', 'sucursal', 'moneda', 'subtotal', 'descuento_total', 'iva_total', 'total', 'vigencia_hasta']));
    if ($partidas !== null) {
        $r['partidas'] = array_map(fn($p) => sprintf('%s × %s @ %s%s', rtrim(rtrim(number_format((float)$p['cantidad'], 2, '.', ''), '0'), '.'),
            $p['descripcion'], number_format((float)$p['precio_unitario'], 2, '.', ''), (float)$p['descuento_pct'] > 0 ? ' (-' . (float)$p['descuento_pct'] . '%)' : ''), $partidas);
    }
    return $r;
}

/**
 * Crea (id = 0) o actualiza un borrador con su encabezado y partidas.
 * $extra: incidencia_id al crear desde una incidencia.
 */
function cotGuardar(PDO $pdo, int $id, array $datos, array $partidasEntrada, string $versionCliente = '', bool $forzar = false, array $extra = []): array {
    $cfg = cotConfig();
    $enc = cotValidarEncabezado($pdo, $datos, $cfg);

    $pdo->beginTransaction();
    try {
        $existentes = [];
        $anterior = null;
        if ($id) {
            $anterior = cotFila($pdo, $id, true);
            if (!$anterior) throw new CotizacionException('La cotización no existe o fue eliminada.', 404, 'no_existe');
            cotExigir(cotPuedeEditar($anterior), $anterior['estatus'] !== 'borrador' || $anterior['reemplazada_por']
                ? 'Solo se edita una cotización en borrador. Para cambiar una enviada crea una nueva versión.'
                : 'Solo puedes editar los borradores que tú creaste desde una orden de servicio.');
            $existentes = cotPartidas($pdo, $id);
            if ($versionCliente !== '' && !$forzar && cotVersionHuella($anterior, $existentes) !== $versionCliente) {
                throw new CotizacionException('La cotización se modificó en otro lugar después de que la abriste.', 409, 'conflicto', [
                    'modificado_por' => cotUltimoCambio($pdo, $id),
                ]);
            }
            // O-13: con partidas, cambiar la moneda convierte sus precios (con confirmación)
            $factorMoneda = null;
            if ($existentes && $anterior['moneda'] !== $enc['moneda']) {
                $tcAntes = $anterior['moneda'] === 'MXN' ? 1.0 : (float)$anterior['tipo_cambio'];
                $tcNuevo = $enc['moneda'] === 'MXN' ? 1.0 : (float)$enc['tipo_cambio'];
                if (empty($datos['convertir_moneda'])) {
                    throw new CotizacionException('La cotización tiene partidas en ' . $anterior['moneda'] . '. Confirma para convertirlas a ' . $enc['moneda']
                        . ($tcNuevo !== 1.0 || $tcAntes !== 1.0 ? ' al tipo de cambio ' . rtrim(rtrim(number_format(max($tcNuevo, $tcAntes), 4), '0'), '.') : '') . '.',
                        422, 'convertir_moneda');
                }
                if ($tcAntes <= 0 || $tcNuevo <= 0) throw new CotizacionException('Indica el tipo de cambio para convertir las partidas.');
                $factorMoneda = $tcAntes / $tcNuevo;
            }
        } else {
            cotExigir(cotAccesoModulo() || !empty($extra['incidencia_id']));
        }

        $partidas = cotPrepararPartidas($pdo, $partidasEntrada, $existentes, (float)$enc['tipo_cambio'], $cfg['iva'],
            array_map('intval', $extra['instaladas'] ?? []), $enc['moneda']);
        if (!empty($factorMoneda)) {  // O-13: solo las partidas que ya estaban (las nuevas vienen en la moneda nueva)
            foreach ($partidas as &$pc) {
                if (!$pc['id']) continue;
                $pc['precio_unitario'] = cotR($pc['precio_unitario'] * $factorMoneda);
                $pc['costo_unitario'] = cotR($pc['costo_unitario'] * $factorMoneda);
            }
            unset($pc);
        }
        $totales = cotCalcularTotales($partidas, (bool)$enc['precios_incluyen_iva']);
        $excedidos = cotDescuentosExcedidos($totales, $cfg['descuento_max']);
        if ($excedidos && !cotPuedeAutorizarDescuentos()) {
            throw new CotizacionException(
                'El descuento rebasa el máximo permitido (' . rtrim(rtrim(number_format($cfg['descuento_max'], 2), '0'), '.') . '%) y tu usuario no puede autorizarlo: ' . implode('; ', $excedidos) . '. Pide a un administrador que lo autorice.',
                403, 'descuento', ['excedidos' => $excedidos]);
        }

        $cab = $enc + [
            'subtotal' => $totales['subtotal'], 'descuento_total' => $totales['descuento_total'],
            'iva_total' => $totales['iva_total'], 'total' => $totales['total'],
            'actualizado_por' => cotUsuarioId() ?: null,
        ];
        if ($id) {
            $sets = implode(', ', array_map(fn($c) => "`$c` = ?", array_keys($cab)));
            $pdo->prepare("UPDATE cotizaciones SET $sets, actualizado_en = NOW() WHERE id = ?")->execute([...array_values($cab), $id]);
        } else {
            $folio = cotSiguienteFolio($pdo, $cfg['prefijo']);
            $cab += [
                'folio' => $folio, 'folio_base' => $folio, 'version' => 1, 'estatus' => 'borrador',
                'incidencia_id' => $extra['incidencia_id'] ?? null,
                'creado_por' => cotUsuarioId() ?: null,
                'creado_por_nombre' => $_SESSION['nombre'] ?? null,
            ];
            $cols = implode(', ', array_map(fn($c) => "`$c`", array_keys($cab)));
            $pdo->prepare("INSERT INTO cotizaciones ($cols) VALUES (" . implode(', ', array_fill(0, count($cab), '?')) . ')')->execute(array_values($cab));
            $id = (int)$pdo->lastInsertId();
        }

        // Partidas: se borran las que ya no vienen y se insertan/actualizan las demás
        $conservar = array_filter(array_column($totales['partidas'], 'id'));
        $borrar = array_diff(array_map('intval', array_column($existentes, 'id')), $conservar);
        if ($borrar) {
            $pdo->prepare('DELETE FROM cotizacion_partidas WHERE cotizacion_id = ? AND id IN (' . implode(',', array_fill(0, count($borrar), '?')) . ')')
                ->execute([$id, ...array_values($borrar)]);
        }
        $ins = $pdo->prepare('INSERT INTO cotizacion_partidas (cotizacion_id, ' . implode(', ', COT_CAMPOS_PARTIDA) . ') VALUES (?' . str_repeat(', ?', count(COT_CAMPOS_PARTIDA)) . ')');
        $upd = $pdo->prepare('UPDATE cotizacion_partidas SET ' . implode(', ', array_map(fn($c) => "$c = ?", COT_CAMPOS_PARTIDA)) . ' WHERE id = ? AND cotizacion_id = ?');
        foreach ($totales['partidas'] as $p) {
            $valores = array_map(fn($c) => $p[$c], COT_CAMPOS_PARTIDA);
            if ($p['id']) $upd->execute([...$valores, $p['id'], $id]);
            else $ins->execute([$id, ...$valores]);
        }

        if (!empty($extra['instaladas'])) {  // cotización posterior al servicio (C-H09)
            $ids = array_values(array_map('intval', $extra['instaladas']));
            $pdo->prepare("UPDATE cotizacion_partidas SET cargada_incidencia = 1
                           WHERE cotizacion_id = ? AND tipo IN ('refaccion', 'equipo') AND articulo_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ')')
                ->execute([$id, ...$ids]);
        }
        require_once __DIR__ . '/sucursales_helpers.php';
        sucLigarRegistro($pdo, 'cotizaciones', $id);  // D-44
        $nueva = cotFila($pdo, $id);
        $nuevas = cotPartidas($pdo, $id);
        registrarAuditoria('cotizaciones', $id, $anterior ? 'UPDATE' : 'CREATE',
            $anterior ? cotResumen($anterior, $existentes) : null, cotResumen($nueva, $nuevas), $nueva['folio']);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return cotObtener($pdo, $id);
}

function cotUltimoCambio(PDO $pdo, int $id): ?string {
    $st = $pdo->prepare("SELECT usuario_nombre, creado_en FROM auditoria WHERE tabla = 'cotizaciones' AND registro_id = ? ORDER BY id DESC LIMIT 1");
    $st->execute([(string)$id]);
    $f = $st->fetch(PDO::FETCH_ASSOC);
    return $f ? ($f['usuario_nombre'] ?? '?') . ' el ' . $f['creado_en'] : null;
}

/** Datos iniciales de un borrador desde una incidencia (cliente, sucursal, contacto). */
function cotDatosDesdeIncidencia(PDO $pdo, int $incidenciaId): array {
    $st = $pdo->prepare('SELECT id, numero_incidente, cliente, sucursal, contacto, falla, accion, tecnico FROM incidencias WHERE id = ?');
    $st->execute([$incidenciaId]);
    $inc = $st->fetch(PDO::FETCH_ASSOC);
    require_once __DIR__ . '/incidencias_helpers.php';
    if ($inc && !ordenVisibleParaSesion($pdo, $inc['tecnico'] ?? '')) $inc = false;  // D-9
    if ($inc) unset($inc['tecnico']);
    if (!$inc) throw new CotizacionException('La orden de servicio no existe.', 404, 'no_existe');
    // Las incidencias guardan el nombre del cliente: se busca en el catálogo
    $st = $pdo->prepare('SELECT id FROM clientes WHERE UPPER(TRIM(nombre)) = UPPER(TRIM(?)) LIMIT 1');
    $st->execute([$inc['cliente']]);
    $clienteId = (int)$st->fetchColumn();
    if (!$clienteId) throw new CotizacionException("El cliente \"{$inc['cliente']}\" de la orden de servicio no está en el catálogo de clientes. Dalo de alta y vuelve a intentar.");
    $contactoId = null;
    if ($inc['contacto']) {
        $st = $pdo->prepare('SELECT id FROM cliente_contactos WHERE cliente_id = ? AND UPPER(TRIM(nombre)) = UPPER(TRIM(?)) LIMIT 1');
        $st->execute([$clienteId, $inc['contacto']]);
        $contactoId = (int)$st->fetchColumn() ?: null;
    }
    $cfg = cotConfig();
    return [
        'datos' => [
            'cliente_id' => $clienteId, 'sucursal' => $inc['sucursal'], 'contacto_id' => $contactoId,
            'fecha' => date('Y-m-d'), 'vigencia_hasta' => date('Y-m-d', strtotime('+' . $cfg['vigencia_dias'] . ' days')),
            'moneda' => 'MXN', 'tipo_cambio' => 1, 'precios_incluyen_iva' => 0,
            'condiciones' => $cfg['terminos'],
            // O-14: las notas internas son del usuario; el origen se muestra como etiqueta del sistema
            'notas_internas' => null,
        ],
        'incidencia' => $inc,
    ];
}

/** Refacciones registradas en la incidencia (netas de devoluciones), como partidas nuevas. */
function cotPartidasDeConsumos(PDO $pdo, int $incidenciaId): array {
    if (!invTablasDisponibles($pdo)) return [];
    // L-8 · D-51: lo aplicado se cotiza aunque el artículo se haya desactivado después, y al
    // precio con que se aplicó (promedio si salió a distintos precios), no al del catálogo de hoy
    $precio = invTienePrecioCongelado($pdo) ? 'COALESCE(m.precio_unitario, a.precio_venta)' : 'a.precio_venta';
    // H-10: una partida por cada precio distinto (sin promediar, para no descuadrar centavos)
    $st = $pdo->prepare(
        "SELECT m.articulo_id, $precio AS precio, SUM(-m.cantidad) AS cantidad
         FROM inventario_movimientos m JOIN inventario_articulos a ON a.id = m.articulo_id
         WHERE m.referencia_tipo = 'incidencia' AND m.referencia_id = ?
         GROUP BY m.articulo_id, precio
         HAVING cantidad > 0
         ORDER BY MIN(m.creado_en), m.articulo_id"
    );
    $st->execute([$incidenciaId]);
    $partida = fn($f) => ['articulo_id' => (int)$f['articulo_id'], 'cantidad' => (float)$f['cantidad'], 'precio_unitario' => round((float)$f['precio'], 2)];
    $partidas = array_map($partida, $st->fetchAll(PDO::FETCH_ASSOC));
    // D-23: también los servicios y la mano de obra aplicados (no tienen existencias)
    if (invServiciosDisponibles($pdo)) {
        $st = $pdo->prepare('SELECT s.articulo_id, s.precio_unitario AS precio, SUM(s.cantidad) AS cantidad FROM incidencia_servicios s
                             WHERE s.incidencia_id = ? GROUP BY s.articulo_id, s.precio_unitario HAVING cantidad > 0 ORDER BY MIN(s.id)');
        $st->execute([$incidenciaId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $f) $partidas[] = $partida($f);
    }
    return $partidas;
}

/**
 * Borrador ligado a la incidencia. Con $conRefacciones (cotización posterior
 * al servicio) trae las refacciones ya registradas, marcadas como instaladas,
 * y en las notas para el cliente la falla y el trabajo realizado.
 */
function cotCrearDesdeIncidencia(PDO $pdo, int $incidenciaId, bool $conRefacciones = false): array {
    $base = cotDatosDesdeIncidencia($pdo, $incidenciaId);
    $partidas = [];
    if ($conRefacciones) {
        $partidas = cotPartidasDeConsumos($pdo, $incidenciaId);
        if (!$partidas) throw new CotizacionException('Esta orden de servicio no tiene conceptos aplicados. Regístralos en «Conceptos aplicados» o crea la cotización en blanco.');
        $inc = $base['incidencia'];
        $base['datos']['notas_cliente'] = implode("\n", array_filter([
            trim((string)$inc['falla']) !== '' ? 'Servicio: ' . trim($inc['falla']) : '',
            trim((string)$inc['accion']) !== '' ? 'Trabajo realizado: ' . trim($inc['accion']) : '',
        ])) ?: null;
    }
    // C-H09: las refacciones ya instaladas se marcan dentro de la misma transacción del alta
    return cotGuardar($pdo, 0, $base['datos'], $partidas, '', false,
        ['incidencia_id' => $incidenciaId, 'instaladas' => array_column($partidas, 'articulo_id')]);
}

// ---------------------------------------------------------------- estatus

/**
 * Cambia el estatus validando la transición. $d: aprobada_por, aceptadas
 * (ids de partidas que el cliente aceptó; por defecto todas), motivo.
 * Devuelve [cotización, avisos] con lo que la interfaz puede ofrecer después.
 */
function cotCambiarEstatus(PDO $pdo, int $id, string $nuevo, array $d = []): array {
    cotExigir(cotAccesoModulo(), 'Tu rol puede preparar el borrador, pero enviarlo, aprobarlo o rechazarlo le corresponde a la oficina.');
    if (!in_array($nuevo, ['enviada', 'aprobada', 'rechazada'], true)) throw new CotizacionException('Estatus no válido.');
    $avisos = [];
    $pdo->beginTransaction();
    try {
        $cot = cotFila($pdo, $id, true);
        if (!$cot) throw new CotizacionException('La cotización no existe.', 404, 'no_existe');
        if ($cot['reemplazada_por']) throw new CotizacionException('Esta versión fue sustituida por otra: solo es de consulta.');
        // L-19: una vencida se aprueba si el cliente la aprobó dentro de la vigencia (lo confirma quien la captura)
        // (L2-8: también si sigue «enviada» pero su vigencia ya pasó y nadie la ha marcado vencida)
        $aprobadaVencida = $nuevo === 'aprobada' && ($cot['estatus'] === 'vencida' || $cot['vigencia_hasta'] < date('Y-m-d'));
        if ($aprobadaVencida && empty($d['aprobada_en_vigencia'])) {
            throw new CotizacionException('Venció el ' . date('d/m/Y', strtotime($cot['vigencia_hasta'])) . '. Si el cliente la aprobó antes de esa fecha, confírmalo; si no, crea una nueva versión.', 409, 'confirmar_vigencia');
        }
        if (!$aprobadaVencida && !in_array($nuevo, COT_TRANSICIONES[$cot['estatus']] ?? [], true)) {
            throw new CotizacionException(sprintf('No se puede pasar de "%s" a "%s".', $cot['estatus'], $nuevo));
        }
        cotCongelarIncluye($pdo, $id);  // L-9 · H-08: al salir del borrador (o si quedó sin congelar)
        $partidas = cotPartidas($pdo, $id);
        $cambios = ['estatus' => $nuevo];

        if ($nuevo === 'enviada') {
            if (!$partidas) throw new CotizacionException('Agrega al menos una partida antes de enviar la cotización.');
            if ((float)$cot['total'] <= 0) throw new CotizacionException('El total de la cotización debe ser mayor a cero.');
            if ($cot['vigencia_hasta'] < date('Y-m-d')) throw new CotizacionException('La vigencia ya pasó: corrígela antes de enviar.');
            $cambios['fecha_envio'] = date('Y-m-d H:i:s');
        } elseif ($nuevo === 'rechazada') {
            $motivo = trim((string)($d['motivo'] ?? ''));
            if ($motivo === '') throw new CotizacionException('Escribe el motivo del rechazo.', 400, 'faltan_datos');
            $cambios['motivo_rechazo'] = mb_substr($motivo, 0, 255);
            if ($cot['incidencia_id']) {
                $avisos['refacciones_instaladas'] = count(array_filter($partidas, fn($p) => $p['tipo'] === 'refaccion' && (int)$p['cargada_incidencia'] === 1));
            }
        } else { // aprobada
            $quien = trim((string)($d['aprobada_por'] ?? ''));
            if ($quien === '') throw new CotizacionException('Escribe el nombre de quien aprueba.', 400, 'faltan_datos');
            // H-03 · D-47: lo que la orden ya tiene aplicado se concilia antes de generar ventas por registrar
            $sinMarcarAntes = array_map(fn($p) => (int)$p['id'], array_filter($partidas, fn($p) => (int)$p['cargada_incidencia'] !== 1));
            $aceptadas = isset($d['aceptadas']) && is_array($d['aceptadas'])
                ? array_map('intval', $d['aceptadas']) : array_map('intval', array_column($partidas, 'id'));
            $aceptadasPartidas = array_values(array_filter($partidas, fn($p) => in_array((int)$p['id'], $aceptadas, true)));
            if (!$aceptadasPartidas) throw new CotizacionException('Marca al menos una partida aceptada por el cliente. Si no aceptó nada, márcala como rechazada.');
            $tot = cotCalcularTotales($aceptadasPartidas, (bool)$cot['precios_incluyen_iva']);
            $upd = $pdo->prepare('UPDATE cotizacion_partidas SET aceptada = ? WHERE id = ? AND cotizacion_id = ?');
            foreach ($partidas as $p) $upd->execute([in_array((int)$p['id'], $aceptadas, true) ? 1 : 0, $p['id'], $id]);
            // H-01 (fase 4 c): lo que el cliente no aceptó no queda como «registrado en la orden»
            $pdo->prepare('UPDATE cotizacion_partidas SET cargada_incidencia = 0 WHERE cotizacion_id = ? AND aceptada = 0 AND cargada_incidencia = 1')->execute([$id]);
            // H-03 · D-47: con las aceptadas ya guardadas, lo que la orden ya tiene aplicado se concilia (esta
            // cotización cuenta como aprobada) antes de generar ventas por registrar
            if ($cot['incidencia_id']) {
                foreach (array_unique(array_filter(array_map(fn($p) => in_array($p['tipo'], ['refaccion', 'equipo'], true) ? (int)$p['articulo_id'] : 0, $aceptadasPartidas))) as $artAplicado) {
                    cotConciliarAplicado($pdo, (int)$cot['incidencia_id'], $artAplicado, null, $id);
                }
                $frescas = [];
                foreach (cotPartidas($pdo, $id) as $p) $frescas[(int)$p['id']] = $p;
                $aceptadasPartidas = array_values(array_map(fn($p) => $frescas[(int)$p['id']] ?? $p, $aceptadasPartidas));
            }
            $cambios += [
                'aprobada_por_nombre' => mb_substr($quien, 0, 150),
                'fecha_aprobacion' => date('Y-m-d H:i:s'),
                'total_aprobado' => $tot['total'],
                'aprobacion_parcial' => count($aceptadasPartidas) < count($partidas) ? 1 : 0,
            ];
            if ($aprobadaVencida) $avisos['aprobada_vencida'] = true;
            $avisos['ventas_por_registrar'] = cotGenerarVentasPorRegistrar($pdo, $id, $aceptadasPartidas);
            if ($cot['incidencia_id']) {
                $st = $pdo->prepare('SELECT estatus FROM incidencias WHERE id = ?');
                $st->execute([$cot['incidencia_id']]);
                $avisos['incidencia_pendiente'] = $st->fetchColumn() === 'Pendiente';
                $avisos['refacciones_para_incidencia'] = count(array_filter($aceptadasPartidas, fn($p) => $p['tipo'] === 'refaccion'));
                // D-47: refacciones aceptadas, sin marcar, que la orden ya tiene aplicadas (riesgo de registrarlas dos veces)
                $yaAplicadas = array_column(cotPartidasDeConsumos($pdo, (int)$cot['incidencia_id']), 'articulo_id');
                $avisos['refacciones_ya_aplicadas'] = array_values(array_map(fn($p) => $p['descripcion'], array_filter($aceptadasPartidas,
                    fn($p) => $p['tipo'] === 'refaccion' && in_array((int)$p['articulo_id'], $yaAplicadas, true)
                        && ((int)$p['cargada_incidencia'] !== 1 || in_array((int)$p['id'], $sinMarcarAntes, true)))));  // (H-03: o recién conciliadas)
            }
        }
        $sets = implode(', ', array_map(fn($c) => "`$c` = ?", array_keys($cambios)));
        $pdo->prepare("UPDATE cotizaciones SET $sets, actualizado_por = ?, actualizado_en = NOW() WHERE id = ?")
            ->execute([...array_values($cambios), cotUsuarioId() ?: null, $id]);
        registrarAuditoria('cotizaciones', $id, 'ESTATUS', ['estatus' => $cot['estatus']], $cambios + (isset($aceptadasPartidas) ? [
            'partidas_aceptadas' => array_column($aceptadasPartidas, 'descripcion'),
        ] : []), $cot['folio']);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return [cotObtener($pdo, $id), $avisos];
}

/**
 * Una venta por registrar por cada modelo de equipo aceptado: equipos iguales
 * (mismo artículo del catálogo) se juntan en una sola venta con su cantidad.
 */
/**
 * H-09 · L2-6 · L2-7 · H-03 · H-02 de la fase 4 (D-47): concilia lo aplicado en la orden con las partidas
 * de sus cotizaciones. Lo aplicado se reparte entre las partidas en orden (primero las aceptadas de
 * cotizaciones aprobadas; $aprobando = la que se está aprobando cuenta como aprobada) y solo se marcan
 * como «ya registradas en la orden» las que alcanza a cubrir completas. Si una devolución deja menos de lo
 * marcado, se desmarcan las últimas. Un equipo ya instalado desde la orden descuenta sus unidades de la
 * «venta por registrar» (si no, se descontaría otra vez). Dentro de la transacción.
 */
function cotConciliarAplicado(PDO $pdo, int $incidenciaId, int $articuloId, ?string $folio = null, int $aprobando = 0): void {
    if (!invTablasDisponibles($pdo) || !$pdo->query("SHOW TABLES LIKE 'cotizacion_partidas'")->fetch()) return;
    $neto = invConsumoPorReferencia($pdo, 'incidencia', $incidenciaId)[$articuloId] ?? 0;
    // (H-02 fase 4 b) en una cotización aprobada, lo que el cliente no aceptó no queda como «registrado»
    $pdo->prepare("UPDATE cotizacion_partidas p JOIN cotizaciones c ON c.id = p.cotizacion_id SET p.cargada_incidencia = 0
                   WHERE c.incidencia_id = ? AND p.articulo_id = ? AND (c.estatus IN ('aprobada', 'convertida') OR c.id = ?)
                     AND p.aceptada = 0 AND p.cargada_incidencia = 1")->execute([$incidenciaId, $articuloId, $aprobando]);  // (H-01 fase 4 c: también la que se aprueba)
    $st = $pdo->prepare("SELECT p.id, p.cantidad, p.cargada_incidencia, p.tipo, p.cotizacion_id, c.estatus,
                                ((c.estatus IN ('aprobada', 'convertida') OR c.id = ?) AND p.aceptada = 1) AS vigente
                         FROM cotizacion_partidas p JOIN cotizaciones c ON c.id = p.cotizacion_id
                         WHERE c.incidencia_id = ? AND p.articulo_id = ? AND p.tipo IN ('refaccion', 'equipo') AND c.reemplazada_por IS NULL
                           AND (((c.estatus IN ('aprobada', 'convertida') OR c.id = ?) AND p.aceptada = 1)
                                OR (c.estatus IN ('borrador', 'enviada', 'vencida') AND c.id <> ?))
                         ORDER BY vigente DESC, p.cargada_incidencia DESC, c.id, p.id");  // (lo ya marcado en una aprobada se conserva)
    $st->execute([$aprobando, $incidenciaId, $articuloId, $aprobando, $aprobando]);
    $partidas = $st->fetchAll(PDO::FETCH_ASSOC);
    // Se recalcula desde cero en orden de prioridad: lo aplicado cubre primero a las aprobadas (si un
    // borrador viejo lo tenía, pasa a la aprobada) y una devolución desmarca lo que ya no alcanza
    $cubre = [];
    $libre = $neto;
    foreach ($partidas as $p) {
        $cant = (float)$p['cantidad'];
        if ($cant <= $libre + 0.0001) { $cubre[(int)$p['id']] = true; $libre -= $cant; }
        // Una marca que ya existe se conserva mientras quede algo aplicado (devolución parcial): si se
        // quitara, «Cargar refacciones» volvería a descontar piezas que siguen instaladas
        elseif ((int)$p['cargada_incidencia'] === 1 && $libre > 0.0001) { $cubre[(int)$p['id']] = true; $libre = 0.0; }
    }
    foreach ($partidas as $p) {
        $antes = (int)$p['cargada_incidencia'] === 1;
        $ahora = isset($cubre[(int)$p['id']]);
        if ($antes && !$ahora) {
            $pdo->prepare('UPDATE cotizacion_partidas SET cargada_incidencia = 0 WHERE id = ?')->execute([(int)$p['id']]);
            // H-02 (fase 4 b): un equipo devuelto al almacén vuelve a quedar pendiente por registrar en su venta
            if ($p['tipo'] === 'equipo' && in_array($p['estatus'], ['aprobada', 'convertida'], true)) {
                $sv = $pdo->prepare("SELECT id, estado FROM cotizacion_ventas WHERE cotizacion_id = ? AND articulo_id = ? AND estado IN ('por_registrar', 'descartada')
                                     ORDER BY estado = 'por_registrar' DESC, id DESC LIMIT 1 FOR UPDATE");
                $sv->execute([(int)$p['cotizacion_id'], $articuloId]);
                if (!($cv = $sv->fetch(PDO::FETCH_ASSOC))) {
                    // H-02 (fase 4 c): si nunca se generó (estaba instalado al aprobar), se crea ahora
                    $pp = $pdo->prepare('SELECT descripcion, marca, modelo FROM cotizacion_partidas WHERE id = ?');
                    $pp->execute([(int)$p['id']]);
                    $dp = $pp->fetch(PDO::FETCH_ASSOC) ?: ['descripcion' => 'Equipo', 'marca' => null, 'modelo' => null];
                    $pdo->prepare('INSERT INTO cotizacion_ventas (cotizacion_id, articulo_id, descripcion, marca, modelo, cantidad) VALUES (?, ?, ?, ?, ?, ?)')
                        ->execute([(int)$p['cotizacion_id'], $articuloId, $dp['descripcion'], $dp['marca'], $dp['modelo'], (float)$p['cantidad']]);
                    $cv = ['id' => 0, 'estado' => 'nueva'];
                }
                if ($p['estatus'] === 'convertida') {  // vuelve a tener algo por registrar
                    $pdo->prepare("UPDATE cotizaciones SET estatus = 'aprobada', actualizado_en = NOW() WHERE id = ? AND estatus = 'convertida'")->execute([(int)$p['cotizacion_id']]);
                }
                if ($cv['estado'] !== 'nueva') {
                    $cv['estado'] === 'por_registrar'
                        ? $pdo->prepare('UPDATE cotizacion_ventas SET cantidad = cantidad + ?, actualizado_en = NOW() WHERE id = ?')->execute([(float)$p['cantidad'], (int)$cv['id']])
                        : $pdo->prepare("UPDATE cotizacion_ventas SET estado = 'por_registrar', cantidad = ?, actualizado_en = NOW() WHERE id = ?")->execute([(float)$p['cantidad'], (int)$cv['id']]);
                    registrarAuditoria('cotizaciones', (int)$p['cotizacion_id'], 'UPDATE', null, ['venta_por_registrar' => (float)$p['cantidad'] . ' devuelto(s) al almacén desde la orden ' . ($folio ?? '')]);
                }
            }
        }
        if ($antes || !$ahora) continue;
        $pdo->prepare('UPDATE cotizacion_partidas SET cargada_incidencia = 1 WHERE id = ?')->execute([(int)$p['id']]);
        if ($p['tipo'] === 'equipo' && in_array($p['estatus'], ['aprobada', 'convertida'], true)) {
            // Solo las unidades instaladas salen de lo pendiente por registrar
            $sv = $pdo->prepare("SELECT id, cantidad FROM cotizacion_ventas WHERE cotizacion_id = ? AND articulo_id = ? AND estado = 'por_registrar' ORDER BY id LIMIT 1 FOR UPDATE");
            $sv->execute([(int)$p['cotizacion_id'], $articuloId]);
            if ($cv = $sv->fetch(PDO::FETCH_ASSOC)) {
                $resta = round((float)$cv['cantidad'] - (float)$p['cantidad'], 2);
                if ($resta > 0.0001) {
                    $pdo->prepare('UPDATE cotizacion_ventas SET cantidad = ?, actualizado_en = NOW() WHERE id = ?')->execute([$resta, (int)$cv['id']]);
                } else {
                    $pdo->prepare("UPDATE cotizacion_ventas SET estado = 'descartada', actualizado_en = NOW() WHERE id = ?")->execute([(int)$cv['id']]);
                }
                registrarAuditoria('cotizaciones', (int)$p['cotizacion_id'], 'UPDATE', null,
                    ['venta_por_registrar' => (float)$p['cantidad'] . ' instalado(s) desde la orden ' . ($folio ?? '') . ($resta > 0.0001 ? ", quedan $resta" : ': descartada')]);
                if ($resta <= 0.0001 && ($cotFila = cotFila($pdo, (int)$p['cotizacion_id']))) cotCerrarSiConvertida($pdo, $cotFila);
            }
        }
    }
}

function cotGenerarVentasPorRegistrar(PDO $pdo, int $id, array $aceptadas): int {
    $grupos = [];
    foreach ($aceptadas as $p) {
        // D-47: un equipo de reemplazo ya instalado en la orden ya salió del inventario y ya está en el padrón
        if ($p['tipo'] !== 'equipo' || (int)$p['cargada_incidencia'] === 1) continue;
        $clave = $p['articulo_id'] ? 'a' . $p['articulo_id'] : 'd' . mb_strtoupper(trim($p['descripcion'] . '|' . $p['marca'] . '|' . $p['modelo']));
        $grupos[$clave] ??= ['articulo_id' => $p['articulo_id'], 'descripcion' => $p['descripcion'], 'marca' => $p['marca'], 'modelo' => $p['modelo'], 'cantidad' => 0.0];
        $grupos[$clave]['cantidad'] += (float)$p['cantidad'];
    }
    $ins = $pdo->prepare('INSERT INTO cotizacion_ventas (cotizacion_id, articulo_id, descripcion, marca, modelo, cantidad) VALUES (?, ?, ?, ?, ?, ?)');
    foreach ($grupos as $g) $ins->execute([$id, $g['articulo_id'], $g['descripcion'], $g['marca'], $g['modelo'], $g['cantidad']]);
    return count($grupos);
}

// ---------------------------------------------------------------- versiones

/**
 * Copia una cotización (con sus partidas congeladas) como borrador nuevo.
 * $conservarInstaladas: una nueva versión es el mismo trabajo, así que lo que
 * ya se registró en la incidencia sigue marcado (no se descuenta dos veces).
 */
function cotCopiar(PDO $pdo, array $origen, string $folio, string $folioBase, int $version, ?int $origenId, bool $conservarInstaladas = false): int {
    $cfg = cotConfig();
    $copia = array_intersect_key($origen, array_flip(['cliente_id', 'cliente_nombre', 'sucursal', 'sucursal_id', 'contacto_id', 'contacto_nombre', 'incidencia_id',
        'moneda', 'tipo_cambio', 'precios_incluyen_iva', 'condiciones', 'notas_cliente', 'notas_internas', 'subtotal', 'descuento_total', 'iva_total', 'total']));
    $copia += [
        'folio' => $folio, 'folio_base' => $folioBase, 'version' => $version, 'cotizacion_origen_id' => $origenId,
        'estatus' => 'borrador', 'fecha' => date('Y-m-d'),
        'vigencia_hasta' => date('Y-m-d', strtotime('+' . $cfg['vigencia_dias'] . ' days')),
        'creado_por' => cotUsuarioId() ?: null, 'creado_por_nombre' => $_SESSION['nombre'] ?? null,
    ];
    $cols = implode(', ', array_map(fn($c) => "`$c`", array_keys($copia)));
    $pdo->prepare("INSERT INTO cotizaciones ($cols) VALUES (" . implode(', ', array_fill(0, count($copia), '?')) . ')')->execute(array_values($copia));
    $nuevo = (int)$pdo->lastInsertId();
    $instalada = $conservarInstaladas ? 'cargada_incidencia' : '0';
    $pdo->prepare('INSERT INTO cotizacion_partidas (cotizacion_id, ' . implode(', ', COT_CAMPOS_PARTIDA) . ', opcional, cargada_incidencia)
                   SELECT ?, ' . implode(', ', COT_CAMPOS_PARTIDA) . ", opcional, $instalada FROM cotizacion_partidas WHERE cotizacion_id = ? ORDER BY orden, id")
        ->execute([$nuevo, $origen['id']]);
    return $nuevo;
}

/** Nueva versión (-v2, -v3...) de una enviada, vencida o rechazada. */
function cotNuevaVersion(PDO $pdo, int $id): array {
    cotExigir(cotAccesoModulo());
    $pdo->beginTransaction();
    try {
        $cot = cotFila($pdo, $id, true);
        if (!$cot) throw new CotizacionException('La cotización no existe.', 404, 'no_existe');
        if ($cot['reemplazada_por']) throw new CotizacionException('Esta versión ya fue sustituida: trabaja sobre la más reciente.');
        if (!in_array($cot['estatus'], COT_ESTATUS_VERSIONABLES, true)) {
            throw new CotizacionException($cot['estatus'] === 'borrador' ? 'Un borrador se edita directamente.' : 'Una cotización ' . $cot['estatus'] . ' ya no se modifica.');
        }
        $st = $pdo->prepare('SELECT MAX(version) FROM cotizaciones WHERE folio_base = ? FOR UPDATE');
        $st->execute([$cot['folio_base']]);
        $version = (int)$st->fetchColumn() + 1;
        $nuevo = cotCopiar($pdo, $cot, cotFolioVersion($cot['folio_base'], $version), $cot['folio_base'], $version, (int)($cot['cotizacion_origen_id'] ?: $cot['id']), true);
        $pdo->prepare('UPDATE cotizaciones SET reemplazada_por = ?, actualizado_en = NOW() WHERE id = ?')->execute([$nuevo, $id]);
        registrarAuditoria('cotizaciones', $nuevo, 'VERSION', ['folio' => $cot['folio']], ['folio' => cotFolioVersion($cot['folio_base'], $version), 'version' => $version], cotFolioVersion($cot['folio_base'], $version));
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return cotObtener($pdo, $nuevo);
}

/** Copia como cotización nueva (otro folio), con los mismos precios. */
function cotDuplicar(PDO $pdo, int $id): array {
    cotExigir(cotAccesoModulo());
    $pdo->beginTransaction();
    try {
        $cot = cotFila($pdo, $id);
        if (!$cot) throw new CotizacionException('La cotización no existe.', 404, 'no_existe');
        $folio = cotSiguienteFolio($pdo, cotConfig()['prefijo']);
        $cot['incidencia_id'] = null;  // D-47: un duplicado es un trabajo nuevo, sin la orden de servicio
        $nuevo = cotCopiar($pdo, $cot, $folio, $folio, 1, null);
        registrarAuditoria('cotizaciones', $nuevo, 'CREATE', null, ['folio' => $folio, 'duplicada_de' => $cot['folio']], $folio);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return cotObtener($pdo, $nuevo);
}

function cotEliminar(PDO $pdo, int $id): void {
    cotExigir(cotPuedeEliminar(), 'Solo el administrador puede eliminar cotizaciones.');
    $pdo->beginTransaction();
    try {
        $cot = cotFila($pdo, $id, true);
        if (!$cot) throw new CotizacionException('La cotización no existe.', 404, 'no_existe');
        if ($cot['estatus'] !== 'borrador') throw new CotizacionException('Solo se eliminan borradores. Una cotización enviada queda en el historial.');
        $partidas = cotPartidas($pdo, $id);
        // Si era una versión, la anterior vuelve a ser la vigente de la serie
        $pdo->prepare('UPDATE cotizaciones SET reemplazada_por = NULL WHERE reemplazada_por = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM cotizaciones WHERE id = ?')->execute([$id]);
        registrarAuditoria('cotizaciones', $id, 'DELETE', cotResumen($cot, $partidas), null, $cot['folio']);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

// ---------------------------------------------------------------- integración con ventas e incidencias

/** Datos para prellenar Registrar venta desde una venta por registrar. */
function cotVentaPorRegistrar(PDO $pdo, int $cvId): array {
    $st = $pdo->prepare('SELECT cv.*, c.folio, c.cliente_nombre, c.sucursal, c.estatus AS cotizacion_estatus
                         FROM cotizacion_ventas cv JOIN cotizaciones c ON c.id = cv.cotizacion_id WHERE cv.id = ?');
    $st->execute([$cvId]);
    $v = $st->fetch(PDO::FETCH_ASSOC);
    if (!$v) throw new CotizacionException('La venta por registrar no existe.', 404, 'no_existe');
    if ($v['estado'] !== 'por_registrar') throw new CotizacionException('Esa venta ya se registró o se descartó.');
    return $v;
}

/**
 * La llama registro_ventas.php dentro de su transacción al guardar una venta
 * que viene de una cotización. Cuando ya no quedan ventas por registrar, la
 * cotización pasa a "convertida".
 */
function cotMarcarVentaRegistrada(PDO $pdo, int $cvId, int $ventaId, string $ventaFolio): void {
    $st = $pdo->prepare("SELECT * FROM cotizacion_ventas WHERE id = ? FOR UPDATE");
    $st->execute([$cvId]);
    $cv = $st->fetch(PDO::FETCH_ASSOC);
    if (!$cv || $cv['estado'] !== 'por_registrar') throw new RuntimeException('La venta por registrar de la cotización ya no está pendiente.');
    $pdo->prepare("UPDATE cotizacion_ventas SET estado = 'registrada', venta_id = ?, actualizado_en = NOW() WHERE id = ?")->execute([$ventaId, $cvId]);
    $pdo->prepare('UPDATE cotizaciones SET venta_id = COALESCE(venta_id, ?) WHERE id = ?')->execute([$ventaId, $cv['cotizacion_id']]);
    $cot = cotFila($pdo, (int)$cv['cotizacion_id'], true);
    registrarAuditoria('cotizaciones', $cot['id'], 'CONVERSION', null, ['venta' => $ventaFolio, 'equipo' => $cv['descripcion'], 'cantidad' => (float)$cv['cantidad']], $cot['folio']);
    cotCerrarSiConvertida($pdo, $cot);
}

function cotCerrarSiConvertida(PDO $pdo, array $cot): void {
    $st = $pdo->prepare("SELECT SUM(estado = 'por_registrar') AS pendientes, SUM(estado = 'registrada') AS registradas FROM cotizacion_ventas WHERE cotizacion_id = ?");
    $st->execute([$cot['id']]);
    $c = $st->fetch(PDO::FETCH_ASSOC);
    if ($cot['estatus'] === 'aprobada' && (int)$c['pendientes'] === 0 && (int)$c['registradas'] > 0) {
        $pdo->prepare("UPDATE cotizaciones SET estatus = 'convertida', actualizado_en = NOW() WHERE id = ?")->execute([$cot['id']]);
        registrarAuditoria('cotizaciones', $cot['id'], 'ESTATUS', ['estatus' => 'aprobada'], ['estatus' => 'convertida'], $cot['folio']);
    }
}

/** Las tablas de cotizaciones existen en la empresa activa (migración 007). */
function cotDisponible(PDO $pdo): bool {
    try {
        $pdo->query('SELECT 1 FROM cotizaciones LIMIT 1');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Se eliminó una venta: lo que se había registrado desde una cotización vuelve
 * a "por registrar" y la cotización convertida regresa a "aprobada". Corre
 * dentro de la transacción de eliminar_venta.php. Devuelve cuántas
 * cotizaciones se reabrieron. Sin las tablas (migración 007) no hace nada.
 */
function cotLiberarVentaEliminada(PDO $pdo, int $ventaId, ?string $ventaFolio): int {
    if (!cotDisponible($pdo)) return 0;
    $st = $pdo->prepare("SELECT id, cotizacion_id, descripcion FROM cotizacion_ventas WHERE venta_id = ? FOR UPDATE");
    $st->execute([$ventaId]);
    $filas = $st->fetchAll(PDO::FETCH_ASSOC);
    $pdo->prepare('UPDATE cotizaciones SET venta_id = NULL WHERE venta_id = ?')->execute([$ventaId]);
    if (!$filas) return 0;
    $pdo->prepare("UPDATE cotizacion_ventas SET estado = 'por_registrar', venta_id = NULL, actualizado_en = NOW() WHERE venta_id = ?")->execute([$ventaId]);
    $reabiertas = 0;
    foreach (array_unique(array_column($filas, 'cotizacion_id')) as $cotId) {
        $cot = cotFila($pdo, (int)$cotId, true);
        if (!$cot) continue;
        if ($cot['estatus'] === 'convertida') {
            $pdo->prepare("UPDATE cotizaciones SET estatus = 'aprobada', actualizado_en = NOW() WHERE id = ?")->execute([$cot['id']]);
            registrarAuditoria('cotizaciones', $cot['id'], 'ESTATUS', ['estatus' => 'convertida'], ['estatus' => 'aprobada', 'motivo' => 'Se eliminó la venta ' . ($ventaFolio ?? $ventaId)], $cot['folio']);
            $reabiertas++;
        }
    }
    return $reabiertas;
}

function cotDescartarVenta(PDO $pdo, int $cvId): void {
    cotExigir(cotAccesoModulo());
    $pdo->beginTransaction();
    try {
        $v = cotVentaPorRegistrar($pdo, $cvId);
        $pdo->prepare("UPDATE cotizacion_ventas SET estado = 'descartada', actualizado_en = NOW() WHERE id = ?")->execute([$cvId]);
        $cot = cotFila($pdo, (int)$v['cotizacion_id'], true);
        registrarAuditoria('cotizaciones', $cot['id'], 'UPDATE', ['venta_por_registrar' => $v['descripcion']], ['venta_por_registrar' => 'descartada'], $cot['folio']);
        cotCerrarSiConvertida($pdo, $cot);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/**
 * Saca de "Pendiente" la incidencia ligada (con confirmación del usuario): pasa a
 * Asignado si tiene técnico y a Abierto si no (D-5); el motivo de pendiente se borra.
 * Devuelve [folio, estatus nuevo].
 */
function cotReactivarIncidencia(PDO $pdo, int $id): array {
    cotExigir(cotAccesoModulo());
    require_once __DIR__ . '/incidencias_helpers.php';
    $cot = cotFila($pdo, $id);
    if (!$cot || !$cot['incidencia_id']) throw new CotizacionException('La cotización no está ligada a una orden de servicio.');
    $propia = !$pdo->inTransaction();
    if ($propia) $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT * FROM incidencias WHERE id = ? FOR UPDATE');
        $st->execute([$cot['incidencia_id']]);
        $inc = $st->fetch(PDO::FETCH_ASSOC);
        // D-9: el Técnico solo mueve sus órdenes (aunque tenga el módulo de cotizaciones)
        if (!$inc || !ordenVisibleParaSesion($pdo, $inc['tecnico'] ?? '')) throw new CotizacionException('La orden de servicio ligada ya no existe.');
        if ($inc['estatus'] !== 'Pendiente') throw new CotizacionException('La orden de servicio ya no está en Pendiente (está en ' . $inc['estatus'] . ').');
        $nuevo = trim((string)$inc['tecnico']) !== '' ? 'Asignado' : 'Abierto';
        $motivo = array_key_exists('motivo_estatus', $inc) ? ', motivo_estatus = NULL' : '';
        $pdo->prepare("UPDATE incidencias SET estatus = ?$motivo WHERE id = ?")->execute([$nuevo, $inc['id']]);
        registrarAuditoria('incidencias', $inc['id'], 'UPDATE', ['estatus' => 'Pendiente', 'motivo_estatus' => $inc['motivo_estatus'] ?? null],
            ['estatus' => $nuevo, 'motivo' => 'Cotización ' . $cot['folio'] . ' aprobada'], $inc['numero_incidente']);
        if ($propia) $pdo->commit();
    } catch (Throwable $e) {
        if ($propia && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return [$inc['numero_incidente'], $nuevo];
}

/** Registra en la incidencia ligada las refacciones aceptadas (esto sí descuenta inventario). */
function cotCargarRefaccionesIncidencia(PDO $pdo, int $id): int {
    cotExigir(cotAccesoModulo());
    // Orden de bloqueo único: primero la orden y después la cotización (el mismo que
    // usa eliminar-incidencia.php). Así no se descuenta inventario hacia una orden que
    // se está eliminando ni hay interbloqueo (auditorias/2026-11-10-diff-fix-inventario-ordenes.md, H-01).
    // La liga se lee ANTES de abrir la transacción para no fijar una foto vieja de los datos.
    $previa = cotFila($pdo, $id);
    if (!$previa || !$previa['incidencia_id']) throw new CotizacionException('La cotización no está ligada a una orden de servicio.');
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT numero_incidente FROM incidencias WHERE id = ? FOR UPDATE');
        $st->execute([$previa['incidencia_id']]);
        $folioInc = $st->fetchColumn();
        if ($folioInc === false) throw new CotizacionException('La orden de servicio ligada ya no existe.');
        $cot = cotFila($pdo, $id, true);
        if (!$cot || (int)$cot['incidencia_id'] !== (int)$previa['incidencia_id']) throw new CotizacionException('La cotización cambió mientras se procesaba. Intenta de nuevo.');
        if (!in_array($cot['estatus'], ['aprobada', 'convertida'], true)) throw new CotizacionException('Primero marca la cotización como aprobada.');
        $n = 0;
        // Los artículos se bloquean en orden ascendente, como en el resto del inventario
        $porCargar = cotPartidas($pdo, $id);
        usort($porCargar, fn($a, $b) => [(int)$a['articulo_id'], (int)$a['id']] <=> [(int)$b['articulo_id'], (int)$b['id']]);
        $conSerie = [];
        foreach ($porCargar as $p) {
            if ($p['tipo'] !== 'refaccion' || (int)$p['aceptada'] !== 1 || (int)$p['cargada_incidencia'] === 1 || !$p['articulo_id']) continue;
            // L-14 · D-45: lo que controla serie se registra desde la orden, con el N/S de cada pieza
            if (invTieneSeries($pdo) && (int)$pdo->query('SELECT controla_serie FROM inventario_articulos WHERE id = ' . (int)$p['articulo_id'])->fetchColumn() === 1) {
                $conSerie[] = $p['descripcion'];
                continue;
            }
            invRegistrarMovimiento($pdo, (int)$p['articulo_id'], -(float)$p['cantidad'], 'salida',
                'Utilizado en orden de servicio ' . $folioInc . ' (cotización ' . $cot['folio'] . ')', 'incidencia', (int)$cot['incidencia_id'], $folioInc);
            $pdo->prepare('UPDATE cotizacion_partidas SET cargada_incidencia = 1 WHERE id = ?')->execute([$p['id']]);
            $n++;
        }
        $avisoSerie = $conSerie ? ' ' . implode(', ', $conSerie) . (count($conSerie) === 1 ? ' controla' : ' controlan') . ' número de serie: regístralo desde la orden de servicio, con el N/S de cada pieza.' : '';
        if (!$n) throw new CotizacionException($conSerie ? trim($avisoSerie) : 'No hay refacciones aceptadas pendientes de registrar en la orden de servicio.');
        $GLOBALS['cot_aviso_carga'] = trim($avisoSerie);
        $pdo->commit();
        return $n;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
