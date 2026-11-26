<?php
// API de Mi empresa (public/mi-empresa.html): plan y facturación, uso del
// sistema y personalización de la marca. Solo para quien administra la cuenta
// (Administrador o soporte de plataforma).
//
// GET  ?accion=plan        plan, vigencia, límites, pagos, solicitudes y datos fiscales
// GET  ?accion=uso         totales, series de 6 meses, actividad del equipo y movimientos
// GET  ?accion=marca       marca actual y si el plan permite personalizarla
// POST ?accion=guardar_marca   JSON: nombre, eslogan, colores, prefijo_folio, categorias
// POST ?accion=subir_logo      archivo "logo" (PNG, JPG o WEBP, 1 MB)
// POST ?accion=quitar_logo | restablecer
// POST ?accion=guardar_cotizaciones  JSON: prefijo, vigencia_dias, iva, descuento_max, terminos
// POST ?accion=guardar_operacion     JSON: sla_dias, zona_horaria
// GET  ?accion=tarifas | POST ?accion=guardar_tarifas  JSON: tarifa_hora, servicios[{id?, nombre, precio, incluye}] (D-43)
//      (configuración operativa: disponible en todos los planes)
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../auth/audit.php';
require_once __DIR__ . '/../config/contratacion.php';
require_once __DIR__ . '/../config/marca_empresa.php';
require_once __DIR__ . '/../config/branding.php';
require_once __DIR__ . '/lib/cotizaciones_helpers.php';
require_once __DIR__ . '/lib/incidencias_helpers.php';  // sqlOrdenPorAtender (D-4)
require_once __DIR__ . '/lib/tarifas_helpers.php';

function responder(int $codigo, array $datos = []): void {
    http_response_code($codigo);
    echo json_encode(['exito' => $codigo < 400] + $datos, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!rolPuedeContratar($_SESSION['rol'] ?? null)) {
    responder(403, ['mensaje' => 'Esta sección es solo para el administrador de la cuenta.']);
}

$empresa = empresaActual();
$empresaId = (int)$empresa['id'];
$accion = $_GET['accion'] ?? '';
$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/** Inicio, fin y avance (0-100) del periodo vigente: la prueba o el último periodo pagado. */
function periodoVigente(array $e): ?array {
    if ($e['estado'] === 'prueba' && $e['prueba_hasta']) {
        $inicio = substr((string)$e['creado_en'], 0, 10);
        $fin = $e['prueba_hasta'];
    } elseif ($e['estado'] === 'activa' && $e['pagado_hasta']) {
        $fin = $e['pagado_hasta'];
        $inicio = date('Y-m-d', strtotime($fin . (($e['ciclo'] ?? 'mensual') === 'anual' ? ' -1 year' : ' -1 month')));
    } else {
        return null;
    }
    $total = max(1, (strtotime($fin) - strtotime($inicio)) / 86400);
    $transcurrido = (strtotime(date('Y-m-d')) - strtotime($inicio)) / 86400;
    return [
        'inicio' => $inicio, 'fin' => $fin,
        'dias_restantes' => (int)floor((strtotime($fin) - strtotime(date('Y-m-d'))) / 86400),
        'avance' => (int)max(0, min(100, round($transcurrido * 100 / $total))),
    ];
}

/** Valor de una consulta de conteo; 0 si la tabla no existe en esta empresa. */
function contar(PDO $pdo, string $sql): int {
    try {
        return (int)$pdo->query($sql)->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/** Serie mensual (últimos 6 meses) a partir de filas [mes => valor]. */
function serie6(array $datos): array {
    $out = [];
    for ($i = 5; $i >= 0; $i--) {
        $mes = date('Y-m', strtotime(date('Y-m-01') . " -$i months"));
        $out[] = ['mes' => $mes, 'valor' => (int)($datos[$mes] ?? 0)];
    }
    return $out;
}

try {
    switch ("$metodo:$accion") {

    case 'GET:exportar':  // «Exportar mis datos»: un CSV por tabla, en ZIP (también en solo lectura)
        if (!empty($_SESSION['soporte'])) responder(403, ['mensaje' => 'En modo soporte la exportación se hace desde el panel de plataforma.']);
        require_once __DIR__ . '/lib/exportar_helpers.php';
        $zipRuta = expZipEmpresa($pdo);
        register_shutdown_function(fn() => @unlink($zipRuta));  // S2-2: también si se corta la descarga
        if (empresaAdmiteEscrituras()) registrarAuditoria('empresa', $empresaId, 'EXPORTAR', null, ['archivo' => 'ZIP de CSV']);  // H-08
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="datos-' . preg_replace('/[^a-z0-9-]+/', '-', strtolower((string)($empresa['slug'] ?? 'empresa'))) . '-' . date('Ymd') . '.zip"');
        header('Content-Length: ' . filesize($zipRuta));
        header('Cache-Control: private, no-store');
        readfile($zipRuta);
        @unlink($zipRuta);
        exit;

    case 'GET:plan':
        $situacion = situacionEmpresa($empresa);
        $precio = precioEmpresa($pdo, $empresa);
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE empresa_id = ?");
        $stmt->execute([$empresaId]);
        $usuarios = (int)$stmt->fetchColumn();
        $stmt = $pdo->prepare("SELECT fecha_pago, monto, cubre_hasta, metodo, referencia, factura, anulado
                               FROM pagos WHERE empresa_id = ? ORDER BY fecha_pago DESC, id DESC LIMIT 24");
        $stmt->execute([$empresaId]);
        $pagos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $solicitudes = contratacionDisponible($pdo) ? array_map(function ($s) {
            return array_intersect_key($s, array_flip(['referencia', 'plan', 'ciclo', 'monto', 'estado', 'motivo', 'creado_en', 'resuelto_en']));
        }, solicitudesDeEmpresa($pdo, $empresaId, 10)) : [];
        responder(200, [
            'empresa' => [
                'nombre' => $empresa['nombre'], 'alta' => substr((string)$empresa['creado_en'], 0, 10),
                'estado' => $situacion['estado'], 'acceso' => $situacion['acceso'], 'mensaje' => $situacion['mensaje'],
                'verificacion' => !empty($situacion['verificacion']), 'dias' => $situacion['dias'],
                'prueba_hasta' => $empresa['prueba_hasta'], 'pagado_hasta' => $empresa['pagado_hasta'],
                'rfc' => $empresa['rfc'], 'razon_social' => $empresa['razon_social'],
                'contacto_nombre' => $empresa['contacto_nombre'], 'contacto_correo' => $empresa['contacto_correo'],
                'contacto_telefono' => $empresa['contacto_telefono'],
            ],
            'plan' => [
                'nombre' => $precio['plan']['nombre'] ?? '—', 'descripcion' => $precio['plan']['descripcion'] ?? '',
                'ciclo' => $precio['ciclo'], 'por_ciclo' => $precio['por_ciclo'], 'pactado' => $precio['pactado'],
                'max_usuarios' => limiteUsuariosEmpresa($pdo, $empresaId), 'permite_marca' => marcaPersonalizable($pdo, $empresa),
            ],
            'periodo' => periodoVigente($empresa),
            'usuarios' => $usuarios,
            'pagos' => $pagos,
            'solicitudes' => $solicitudes,
            'moneda' => configPlataforma('moneda'),
            'puede_contratar' => empty($_SESSION['soporte']) && puedeContratar($pdo),
            'contacto' => trim(contactoComercial()),
        ]);

    case 'GET:uso':
        $desde = date('Y-m-01', strtotime(date('Y-m-01') . ' -5 months'));
        $cerradoSql = "(estatus LIKE 'Cerrado%')";
        $incMes = $incCerradas = $ventasMes = [];
        try {
            foreach ($pdo->query("SELECT DATE_FORMAT(fecha, '%Y-%m') AS mes, COUNT(*) AS total, SUM($cerradoSql) AS cerradas
                                  FROM incidencias WHERE fecha >= '$desde' GROUP BY mes")->fetchAll(PDO::FETCH_ASSOC) as $f) {
                $incMes[$f['mes']] = $f['total'];
                $incCerradas[$f['mes']] = $f['cerradas'];
            }
            $ventasMes = $pdo->query("SELECT DATE_FORMAT(fecha_registro, '%Y-%m'), COUNT(*) FROM ventas
                                      WHERE fecha_registro >= '$desde' GROUP BY 1")->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (Throwable $e) {
            error_log('Mi empresa (series): ' . $e->getMessage());
        }

        // Equipo: cada usuario con su actividad de los últimos 30 días (analíticas de uso)
        $stmt = $pdo->prepare(
            "SELECT u.id, u.nombre, u.rol, MAX(a.creado_en) AS ultima,
                    COUNT(a.id) AS acciones, COUNT(DISTINCT DATE(a.creado_en)) AS dias_activos
             FROM usuarios u
             LEFT JOIN analytics_eventos a ON a.usuario_id = u.id AND a.empresa_id = u.empresa_id AND a.creado_en >= NOW() - INTERVAL 30 DAY
             WHERE u.empresa_id = ? GROUP BY u.id, u.nombre, u.rol ORDER BY acciones DESC, u.nombre"
        );
        $stmt->execute([$empresaId]);
        $equipo = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stmt = $pdo->prepare("SELECT DATE(creado_en) AS dia, COUNT(*) FROM analytics_eventos
                               WHERE empresa_id = ? AND creado_en >= CURDATE() - INTERVAL 29 DAY GROUP BY dia");
        $stmt->execute([$empresaId]);
        $porDia = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        $actividad = [];
        for ($i = 29; $i >= 0; $i--) {
            $dia = date('Y-m-d', strtotime("-$i days"));
            $actividad[] = ['dia' => $dia, 'valor' => (int)($porDia[$dia] ?? 0)];
        }
        try {
            $movimientos = $pdo->query("SELECT usuario_nombre, accion, tabla, registro_folio, creado_en FROM auditoria ORDER BY id DESC LIMIT 10")
                ->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $movimientos = [];
        }
        responder(200, [
            'totales' => [
                'incidencias' => contar($pdo, "SELECT COUNT(*) FROM incidencias"),
                'abiertas' => contar($pdo, "SELECT COUNT(*) FROM incidencias WHERE " . sqlOrdenPorAtender()),
                'completadas' => contar($pdo, "SELECT COUNT(*) FROM incidencias WHERE estatus = 'Completado'"),
                'cerradas' => contar($pdo, "SELECT COUNT(*) FROM incidencias WHERE $cerradoSql"),
                'canceladas' => contar($pdo, "SELECT COUNT(*) FROM incidencias WHERE estatus = 'Cancelado'"),
                'clientes' => contar($pdo, "SELECT COUNT(*) FROM clientes"),
                'equipos' => contar($pdo, "SELECT COUNT(*) FROM padron_equipos"),
                'ventas' => contar($pdo, "SELECT COUNT(*) FROM ventas"),
                'articulos' => contar($pdo, "SELECT COUNT(*) FROM inventario_articulos"),
                'usuarios' => count($equipo),
                'activos_30' => count(array_filter($equipo, fn($u) => (int)$u['acciones'] > 0)),
            ],
            'almacenamiento' => almacenamientoEmpresa($empresaId),
            'series' => ['incidencias' => serie6($incMes), 'cerradas' => serie6($incCerradas), 'ventas' => serie6($ventasMes)],
            'actividad' => $actividad,
            'equipo' => $equipo,
            'movimientos' => $movimientos,
        ]);

    case 'GET:marca':
        $b = brandConfig();
        $planes = array_values(array_map(fn($p) => $p['nombre'], array_filter(listarPlanes($pdo, true),
            fn($p) => (int)($p['permite_marca'] ?? 1) === 1)));
        responder(200, [
            'marca' => [
                'nombre' => $b['nombre'], 'eslogan' => $b['eslogan'], 'logo' => $b['logo'],
                'logo_propio' => strpos((string)($empresa['marca']['logo'] ?? ''), carpetaLogos($empresaId) . '/') === 0,
                'colores' => ['primario' => $b['colores']['primario'], 'secundario' => $b['colores']['secundario'], 'sidebar' => $b['colores']['sidebar']],
                'prefijo_folio' => $b['prefijo_folio'], 'categorias' => $b['categorias'],
            ],
            'por_defecto' => marcaPorDefecto($empresa['nombre'])['colores'],
            'personalizable' => marcaPersonalizable($pdo, $empresa),
            'demo' => $empresa['estado'] === 'demo',
            'planes_con_marca' => $planes,
            'puede_contratar' => empty($_SESSION['soporte']) && puedeContratar($pdo),
            'cotizaciones' => cotConfig($empresa),
            'operacion' => [
                'sla_dias' => slaDiasEmpresa($empresa),
                'zona_horaria' => (string)($empresa['marca']['zona_horaria'] ?? env('APP_TIMEZONE', 'America/Mexico_City')),
            ],
        ]);

    case 'GET:tarifas':
        responder(200, tarObtener($pdo, $empresa));

    case 'POST:guardar_tarifas':  // D-43
        [$antes, $despues] = tarGuardar($pdo, $empresa, json_decode(file_get_contents('php://input'), true) ?: []);
        if ($antes != $despues) registrarAuditoria('empresa_marca', $empresaId, 'UPDATE', ['tarifas' => $antes], ['tarifas' => $despues], 'Tarifas de servicio');
        responder(200, ['mensaje' => $antes == $despues ? 'Sin cambios.' : 'Tarifas de servicio guardadas.'] + $despues);

    case 'POST:guardar_operacion':
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        $sla = (int)($body['sla_dias'] ?? 0);
        if ($sla < 1 || $sla > SLA_DIAS_MAX) throw new InvalidArgumentException('La meta de cumplimiento debe ser de 1 a ' . SLA_DIAS_MAX . ' días.');
        $zona = trim((string)($body['zona_horaria'] ?? ''));
        if (!in_array($zona, DateTimeZone::listIdentifiers(), true)) throw new InvalidArgumentException('Elige una zona horaria de la lista.');
        $marca = $empresa['marca'];
        $antes = ['sla_dias' => slaDiasEmpresa($empresa), 'zona_horaria' => $marca['zona_horaria'] ?? null];
        $nueva = ['sla_dias' => $sla, 'zona_horaria' => $zona];
        $marca = array_replace($marca, $nueva);
        $pdo->prepare('UPDATE empresas SET marca = ?, actualizado_en = NOW() WHERE id = ?')
            ->execute([json_encode($marca, JSON_UNESCAPED_UNICODE), $empresaId]);
        $cambios = array_keys(array_filter($nueva, fn($v, $k) => $antes[$k] != $v, ARRAY_FILTER_USE_BOTH));
        if ($cambios) {
            registrarAuditoria('empresa_marca', $empresaId, 'UPDATE', array_intersect_key($antes, array_flip($cambios)),
                array_intersect_key($nueva, array_flip($cambios)), 'Operación');
        }
        responder(200, ['mensaje' => $cambios ? 'Configuración de operación guardada.' : 'Sin cambios.']);

    case 'POST:guardar_cotizaciones':
        try {
            $nueva = cotValidarConfig(json_decode(file_get_contents('php://input'), true) ?: []);
        } catch (CotizacionException $e) {
            throw new InvalidArgumentException(mensajeErrorPublico($e));
        }
        $antes = cotConfig($empresa);
        $marca = $empresa['marca'];
        $marca['cotizaciones'] = $nueva;
        $pdo->prepare('UPDATE empresas SET marca = ?, actualizado_en = NOW() WHERE id = ?')
            ->execute([json_encode($marca, JSON_UNESCAPED_UNICODE), $empresaId]);
        $cambios = array_keys(array_filter($nueva, fn($v, $k) => ($antes[$k] ?? null) != $v, ARRAY_FILTER_USE_BOTH));
        if ($cambios) {
            registrarAuditoria('empresa_marca', $empresaId, 'UPDATE', ['cotizaciones' => array_intersect_key($antes, array_flip($cambios))],
                ['cotizaciones' => array_intersect_key($nueva, array_flip($cambios))], 'Cotizaciones');
        }
        responder(200, ['mensaje' => $cambios ? 'Configuración de cotizaciones guardada.' : 'Sin cambios.']);

    case 'POST:guardar_marca':
    case 'POST:subir_logo':
    case 'POST:quitar_logo':
    case 'POST:restablecer':
        if (!marcaPersonalizable($pdo, $empresa)) {
            responder(403, ['mensaje' => $empresa['estado'] === 'demo'
                ? 'La personalización no está disponible en la cuenta de demostración.'
                : 'Tu plan no incluye la personalización de marca. Cámbiate a un plan que la incluya para usarla.']);
        }
        if ($accion === 'guardar_marca') {
            $body = json_decode(file_get_contents('php://input'), true) ?: [];
            [$antes, $despues] = guardarMarcaEmpresa($pdo, $empresaId,
                array_intersect_key($body, array_flip(['nombre', 'eslogan', 'colores', 'prefijo_folio', 'categorias'])));
        } elseif ($accion === 'subir_logo') {
            $antes = $empresa['marca'];
            $logo = guardarLogoEmpresa($pdo, $empresaId, $_FILES['logo'] ?? []);
            $despues = array_replace($antes, ['logo' => $logo]);
        } elseif ($accion === 'quitar_logo') {
            $antes = $empresa['marca'];
            quitarLogoEmpresa($pdo, $empresaId);
            $despues = array_replace($antes, ['logo' => marcaPorDefecto($empresa['nombre'])['logo']]);
        } else {
            [$antes, $despues] = restablecerMarcaEmpresa($pdo, $empresaId);
        }
        $campos = ['nombre', 'eslogan', 'logo', 'colores', 'prefijo_folio', 'categorias'];
        $antes = array_intersect_key($antes, array_flip($campos));
        $despues = array_intersect_key($despues, array_flip($campos));
        $cambios = array_keys(array_filter($despues, fn($v, $k) => ($antes[$k] ?? null) != $v, ARRAY_FILTER_USE_BOTH));
        if ($cambios) {
            registrarAuditoria('empresa_marca', $empresaId, 'UPDATE', array_intersect_key($antes, array_flip($cambios)),
                array_intersect_key($despues, array_flip($cambios)), 'Marca');
            registrarEventoPlataforma($pdo, $empresaId, 'marca', ['origen' => 'cliente', 'accion' => $accion, 'campos' => $cambios]);
        }
        responder(200, ['mensaje' => $cambios ? 'Cambios guardados.' : 'Sin cambios.']);

    default:
        responder(400, ['mensaje' => 'Acción no válida']);
    }
} catch (InvalidArgumentException $e) {
    responder(422, ['mensaje' => mensajeErrorPublico($e)]);
} catch (Throwable $e) {
    error_log('Mi empresa: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    responder(500, ['mensaje' => 'No se pudo completar la operación. Inténtalo de nuevo.']);
}
