<?php
// API del módulo de cotizaciones. Un solo endpoint con ?accion=...
//
//   GET  listar                 filtros: buscar, cliente_id, estatus, desde, hasta, incidencia_id, todas_versiones
//   GET  resumen                indicadores: borradores, enviadas, por vencer (3 días), aprobadas del mes
//   GET  detalle&id=
//   GET  config                 configuración de la empresa y permisos del usuario
//   GET  catalogo&buscar=       productos y servicios del catálogo para agregar partidas
//   GET  clientes | contactos&cliente_id= | sucursales&cliente_id=
//   GET  ventas_por_registrar   (Ventas) | venta_por_registrar&id=
//   GET  pdf&id=[&ver=1]        descarga el PDF (COT-0001_v1_Cliente.pdf); ver=1 lo muestra en línea
//   POST guardar                crea (sin id) o actualiza un borrador (JSON: id, version, datos, partidas, forzar)
//   POST crear_desde_incidencia incidencia_id, incluir_refacciones (cotización posterior al servicio)
//   POST estatus                id, estatus (enviada|aprobada|rechazada), aprobada_por, aceptadas[], motivo
//   POST nueva_version | duplicar | eliminar          id
//   POST reactivar_incidencia | cargar_refacciones    id
//   POST descartar_venta        id (venta por registrar)
//
// Permisos: módulo "cotizaciones". Sin él (rol Técnico) solo se crean y editan
// los borradores propios desde una incidencia. El modo solo lectura de la
// cuenta lo aplica requireAuth() (consultar y descargar PDF sí; modificar no).
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../auth/permisos.php';
require_once __DIR__ . '/lib/cotizaciones_helpers.php';

$accion = $_GET['accion'] ?? '';
$metodo = $_SERVER['REQUEST_METHOD'];
$body = $metodo === 'POST' ? (json_decode(file_get_contents('php://input'), true) ?: []) : [];

if ($accion !== 'pdf') header('Content-Type: application/json');

function responder(array $datos, int $codigo = 200): void {
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}


function cotPorId(PDO $pdo, int $id): array {
    $cot = cotObtener($pdo, $id);
    if (!$cot) throw new CotizacionException('La cotización no existe o fue eliminada.', 404, 'no_existe');
    // S-9: una cotización ajena responde igual que una inexistente (no confirma que existe)
    if (!cotPuedeVer($cot)) throw new CotizacionException('La cotización no existe o fue eliminada.', 404, 'no_existe');
    return $cot;
}

// Todo el módulo exige al menos acceso a cotizaciones o a incidencias (técnico)
if (!tienePermiso('cotizaciones') && !tienePermiso('incidencias')) {
    responder(['exito' => false, 'mensaje' => 'Tu rol no tiene acceso a este módulo.'], 403);
}
if (!cotDisponible($pdo)) {
    responder(['exito' => false, 'mensaje' => 'El módulo de cotizaciones no está instalado en la base de datos (migración 007).'], 500);
}

try {
    switch ("$metodo:$accion") {

    case 'GET:listar':
        cotExigir(cotAccesoModulo() || !empty($_GET['incidencia_id']));
        require_once __DIR__ . '/lib/incidencias_helpers.php';
        if (!cotAccesoModulo() || soloOrdenesPropias()) {
            cotExigir(!empty($_GET['incidencia_id']), 'Indica la orden de servicio.');
            // Técnico: solo de una orden suya, y solo las que creó (D-9, RP-17)
            require_once __DIR__ . '/lib/incidencias_helpers.php';
            cotExigir(ordenVisiblePorId($pdo, (int)$_GET['incidencia_id']) !== null, 'La orden de servicio no existe.');
            if (!cotAccesoModulo()) $_GET['creado_por_sesion'] = 1;
        }
        cotMarcarVencidas($pdo);
        $where = [];
        $params = [];
        if (empty($_GET['todas_versiones']) && empty($_GET['incidencia_id'])) $where[] = 'reemplazada_por IS NULL';
        if (trim($_GET['buscar'] ?? '') !== '') {
            $where[] = '(folio LIKE ? OR cliente_nombre LIKE ? OR sucursal LIKE ?)';
            $like = '%' . trim($_GET['buscar']) . '%';
            array_push($params, $like, $like, $like);
        }
        if (!empty($_GET['creado_por_sesion'])) { $where[] = 'creado_por = ?'; $params[] = cotUsuarioId(); }
        foreach (['cliente_id', 'incidencia_id'] as $campo) {
            if (!empty($_GET[$campo])) { $where[] = "$campo = ?"; $params[] = (int)$_GET[$campo]; }
        }
        if (!empty($_GET['estatus']) && in_array($_GET['estatus'], COT_ESTATUS, true)) { $where[] = 'estatus = ?'; $params[] = $_GET['estatus']; }
        if (!empty($_GET['por_vencer'])) $where[] = "estatus = 'enviada' AND vigencia_hasta BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 DAY)";
        if (!empty($_GET['desde'])) { $where[] = 'fecha >= ?'; $params[] = $_GET['desde']; }
        if (!empty($_GET['hasta'])) { $where[] = 'fecha <= ?'; $params[] = $_GET['hasta']; }
        $st = $pdo->prepare('SELECT id, folio, version, cliente_id, cliente_nombre, sucursal, incidencia_id, estatus, aprobacion_parcial, fecha, vigencia_hasta,
                                    moneda, total, total_aprobado, reemplazada_por, creado_por_nombre
                             FROM cotizaciones' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY id DESC LIMIT 500');
        $st->execute($params);
        responder(['exito' => true, 'cotizaciones' => $st->fetchAll(PDO::FETCH_ASSOC)]);

    case 'GET:resumen':
        cotExigir(cotAccesoModulo());
        cotMarcarVencidas($pdo);
        $k = $pdo->query("SELECT SUM(estatus = 'borrador') AS borradores,
                                 SUM(estatus = 'enviada') AS enviadas,
                                 SUM(estatus = 'enviada' AND vigencia_hasta BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 DAY)) AS por_vencer,
                                 SUM(estatus IN ('aprobada', 'convertida') AND fecha_aprobacion >= DATE_FORMAT(CURDATE(), '%Y-%m-01')) AS aprobadas_mes,
                                 COALESCE(SUM(CASE WHEN estatus IN ('aprobada', 'convertida') AND fecha_aprobacion >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
                                     THEN COALESCE(total_aprobado, total) * tipo_cambio END), 0) AS monto_aprobado_mes
                          FROM cotizaciones WHERE reemplazada_por IS NULL")->fetch(PDO::FETCH_ASSOC);
        $k['ventas_por_registrar'] = (int)$pdo->query("SELECT COUNT(*) FROM cotizacion_ventas WHERE estado = 'por_registrar'")->fetchColumn();
        responder(['exito' => true, 'kpi' => array_map(fn($v) => $v === null ? 0 : $v, $k)]);

    case 'GET:detalle':
        cotMarcarVencidas($pdo);
        responder(['exito' => true, 'cotizacion' => cotPorId($pdo, (int)($_GET['id'] ?? 0))]);

    case 'GET:config':
        $cfg = cotConfig();
        responder(['exito' => true, 'config' => $cfg, 'permisos' => [
            'gestionar' => cotAccesoModulo(),
            've_margen' => cotVeMargen(),
            'autoriza_descuentos' => cotPuedeAutorizarDescuentos(),
            'eliminar' => cotPuedeEliminar(),
            'margen_minimo' => COT_MARGEN_MINIMO,
        ], 'hoy' => date('Y-m-d')]);

    case 'GET:catalogo':
        $buscar = trim($_GET['buscar'] ?? '');
        $where = 'activo = 1';
        $params = [];
        if ($buscar !== '') {
            $where .= ' AND (sku LIKE ? OR nombre LIKE ? OR marca LIKE ? OR modelo LIKE ?)';
            $like = "%$buscar%";
            $params = [$like, $like, $like, $like];
        }
        if (!empty($_GET['tipo']) && invTieneControlExistencia($pdo)) {
            $where .= $_GET['tipo'] === 'servicios' ? ' AND controla_existencia = 0' : ' AND controla_existencia = 1';
        }
        $control = invTieneControlExistencia($pdo) ? 'controla_existencia' : '1 AS controla_existencia';
        $monedaCol = invTieneMoneda($pdo) ? 'moneda' : "'MXN' AS moneda";
        $st = $pdo->prepare("SELECT id, sku, nombre, categoria, marca, modelo, unidad, existencia, stock_minimo, precio_venta, costo_unitario, $monedaCol, $control
                             FROM inventario_articulos WHERE $where ORDER BY controla_existencia DESC, nombre LIMIT 40");
        $st->execute($params);
        $articulos = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!cotVeMargen()) foreach ($articulos as &$a) unset($a['costo_unitario']);
        unset($a);
        responder(['exito' => true, 'articulos' => $articulos]);

    case 'GET:clientes':
        $st = $pdo->query('SELECT id, nombre FROM clientes ORDER BY nombre');
        responder(['exito' => true, 'clientes' => $st->fetchAll(PDO::FETCH_ASSOC)]);

    case 'GET:contactos':
        $st = $pdo->prepare('SELECT id, nombre, telefono, email FROM cliente_contactos WHERE cliente_id = ? ORDER BY nombre');
        $st->execute([(int)($_GET['cliente_id'] ?? 0)]);
        responder(['exito' => true, 'contactos' => $st->fetchAll(PDO::FETCH_ASSOC)]);

    case 'GET:sucursales':
        // No hay catálogo de sucursales: se sugieren las que ya se usaron con ese cliente
        $st = $pdo->prepare('SELECT nombre FROM clientes WHERE id = ?');
        $st->execute([(int)($_GET['cliente_id'] ?? 0)]);
        $nombre = (string)$st->fetchColumn();
        $st = $pdo->prepare("SELECT DISTINCT sucursal FROM (
                                SELECT sucursal FROM incidencias WHERE cliente = ? UNION
                                SELECT sucursal FROM padron_equipos WHERE cliente = ? UNION
                                SELECT sucursal FROM ventas WHERE cliente = ?) s
                             WHERE sucursal IS NOT NULL AND TRIM(sucursal) <> '' ORDER BY sucursal LIMIT 100");
        $st->execute([$nombre, $nombre, $nombre]);
        $sucursales = $st->fetchAll(PDO::FETCH_COLUMN);
        require_once __DIR__ . '/lib/incidencias_helpers.php';
        if (soloOrdenesPropias()) {
            // D-9/D-27: el Técnico solo ve las sucursales de sus propias órdenes
            [$soloMias, $paramsMias] = filtroOrdenesSesion($pdo);
            $st = $pdo->prepare("SELECT sucursal, tecnico FROM incidencias WHERE cliente = ? AND $soloMias AND TRIM(COALESCE(sucursal, '')) <> ''");
            $st->execute(array_merge([$nombre], $paramsMias));
            $sucursales = array_values(array_unique(array_column(filtrarOrdenesVisibles($pdo, $st->fetchAll(PDO::FETCH_ASSOC)), 'sucursal')));
        }
        responder(['exito' => true, 'sucursales' => $sucursales]);

    case 'GET:ventas_por_registrar':
        if (!cotAccesoModulo() && !tienePermiso('ventas')) cotExigir(false);
        $st = $pdo->query("SELECT cv.id, cv.cotizacion_id, cv.descripcion, cv.marca, cv.modelo, cv.cantidad, cv.creado_en, c.folio, c.cliente_nombre, c.sucursal
                           FROM cotizacion_ventas cv JOIN cotizaciones c ON c.id = cv.cotizacion_id
                           WHERE cv.estado = 'por_registrar' ORDER BY cv.id");
        responder(['exito' => true, 'ventas' => $st->fetchAll(PDO::FETCH_ASSOC)]);

    case 'GET:venta_por_registrar':
        if (!cotAccesoModulo() && !tienePermiso('ventas')) cotExigir(false);
        responder(['exito' => true, 'venta' => cotVentaPorRegistrar($pdo, (int)($_GET['id'] ?? 0))]);

    case 'GET:enlace':  // D-54: compartir por WhatsApp con un enlace de solo lectura que caduca
        $cot = cotPorId($pdo, (int)($_GET['id'] ?? 0));
        cotExigir(cotAccesoModulo(), 'Compartir la cotización le corresponde a la oficina.');
        cotExigir($cot['estatus'] !== 'borrador', 'Marca la cotización como enviada antes de compartirla.');
        cotExigir(empty($cot['reemplazada_por']), 'Esta versión fue sustituida por otra: comparte la versión vigente.');  // H-08
        require_once __DIR__ . '/../config/enlaces.php';
        $token = enlaceFirmar($pdo, 'cotizacion', (int)EmpresaContexto::$id, (int)$cot['id']);
        $url = enlaceUrl($token);
        $st = $pdo->prepare('SELECT telefono FROM clientes WHERE id = ?');
        $st->execute([(int)$cot['cliente_id']]);
        $texto = "Hola, te comparto la cotización {$cot['folio']} de " . (EmpresaContexto::$fila['nombre'] ?? 'nuestra empresa')
            . ": $url (el enlace vence el " . date('d/m/Y', time() + ENLACE_DIAS * 86400) . ').';
        if (empresaAdmiteEscrituras()) registrarAuditoria('cotizaciones', (int)$cot['id'], 'COMPARTIR', null, ['enlace_vence' => date('Y-m-d', time() + ENLACE_DIAS * 86400)], $cot['folio']);
        responder(['exito' => true, 'url' => $url, 'whatsapp' => enlaceWhatsapp($texto, (string)$st->fetchColumn()), 'texto' => $texto]);

    case 'GET:pdf':
        $cot = cotPorId($pdo, (int)($_GET['id'] ?? 0));
        require_once __DIR__ . '/lib/cotizacion_pdf.php';
        cotEnviarPdf($pdo, (int)$cot['id'], ($_GET['ver'] ?? '') === '1');  // D-47: ver=1 → vista previa en línea
        exit;

    case 'POST:guardar':
        $id = (int)($body['id'] ?? 0);
        $cot = cotGuardar($pdo, $id, (array)($body['datos'] ?? []), (array)($body['partidas'] ?? []),
            (string)($body['version'] ?? ''), !empty($body['forzar']));
        responder(['exito' => true, 'cotizacion' => $cot]);

    case 'POST:crear_desde_incidencia':
        cotExigir(tienePermiso('incidencias') || cotAccesoModulo());
        $cot = cotCrearDesdeIncidencia($pdo, (int)($body['incidencia_id'] ?? 0), !empty($body['incluir_refacciones']));
        responder(['exito' => true, 'cotizacion' => $cot]);

    case 'POST:estatus':
        [$cot, $avisos] = cotCambiarEstatus($pdo, (int)($body['id'] ?? 0), (string)($body['estatus'] ?? ''), $body);
        responder(['exito' => true, 'cotizacion' => $cot, 'avisos' => $avisos]);

    case 'POST:nueva_version':
        responder(['exito' => true, 'cotizacion' => cotNuevaVersion($pdo, (int)($body['id'] ?? 0))]);

    case 'POST:duplicar':
        responder(['exito' => true, 'cotizacion' => cotDuplicar($pdo, (int)($body['id'] ?? 0))]);

    case 'POST:eliminar':
        cotEliminar($pdo, (int)($body['id'] ?? 0));
        responder(['exito' => true]);

    case 'POST:reactivar_incidencia':
        [$folio, $estatusNuevo] = cotReactivarIncidencia($pdo, (int)($body['id'] ?? 0));
        responder(['exito' => true, 'mensaje' => "La orden de servicio $folio pasó a $estatusNuevo."
            . ($estatusNuevo === 'Abierto' ? ' No tiene técnico: asígnale uno.' : '')]);

    case 'POST:cargar_refacciones':
        $n = cotCargarRefaccionesIncidencia($pdo, (int)($body['id'] ?? 0));
        responder(['exito' => true, 'mensaje' => trim("Se registraron $n refacciones en la orden de servicio y se descontaron del inventario. " . ($GLOBALS['cot_aviso_carga'] ?? ''))]);

    case 'POST:descartar_venta':
        cotDescartarVenta($pdo, (int)($body['id'] ?? 0));
        responder(['exito' => true]);

    default:
        responder(['exito' => false, 'mensaje' => 'Acción no válida'], 400);
    }
} catch (CotizacionException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    responder(['exito' => false, 'mensaje' => mensajeErrorPublico($e), 'motivo' => $e->motivo] + $e->datos, $e->http);
} catch (InventarioException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    responder(['exito' => false, 'mensaje' => mensajeErrorPublico($e)], 422);
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Cotizaciones: ' . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine());
    responder(['exito' => false, 'mensaje' => 'Error interno de cotizaciones.'], 500);
}
