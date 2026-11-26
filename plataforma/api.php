<?php
// API del panel de plataforma. Exclusiva del superadministrador (cuenta sin empresa).
//
// Lectura (GET ?accion=)
//   resumen            indicadores de negocio, series y actividad reciente
//   empresas           cartera con plan, MRR, situación, salud y contacto
//   empresa&id=        ficha completa
//   cobranza&mes=      cobrado, por cobrar, próximas renovaciones y pagos del mes
//   pruebas            embudo de pruebas activas y vencidas sin convertir
//   solicitudes&estado= solicitudes de contratación (pendientes o todas)
//   comprobante&id=    comprobante de una solicitud
//   planes | bitacora | config | admins
//   exportar&id=       ZIP con datos y archivos de una empresa
//   csv_empresas | csv_pagos&desde=&hasta=
// Escritura (POST ?accion=, cuerpo JSON)
//   crear, datos, plan, pago, anular_pago, extender_prueba, estado, marca, nota,
//   soporte, eliminar, guardar_plan, guardar_config, admin_crear, admin_eliminar,
//   mi_password, confirmar_solicitud, rechazar_solicitud
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../auth/permisos.php';
require_once __DIR__ . '/../config/contratacion.php';
require_once __DIR__ . '/../config/marca_empresa.php';

requireSuperadmin();
header('Content-Type: application/json');

function ok(array $datos = []): void {
    echo json_encode(['exito' => true] + $datos, JSON_UNESCAPED_UNICODE);
    exit;
}
function falla(string $mensaje, int $codigo = 400): void {
    http_response_code($codigo);
    echo json_encode(['exito' => false, 'mensaje' => $mensaje], JSON_UNESCAPED_UNICODE);
    exit;
}

/** Actividad de uso por empresa: eventos de los últimos 30 días y último acceso. */
function actividadPorEmpresa(PDO $pdo): array {
    $out = [];
    foreach ($pdo->query(
        "SELECT empresa_id, MAX(creado_en) AS ultima,
                SUM(creado_en >= NOW() - INTERVAL 30 DAY) AS eventos_30,
                COUNT(DISTINCT CASE WHEN creado_en >= NOW() - INTERVAL 30 DAY THEN usuario_id END) AS usuarios_30
         FROM analytics_eventos WHERE empresa_id IS NOT NULL GROUP BY empresa_id"
    )->fetchAll(PDO::FETCH_ASSOC) as $f) {
        $out[(int)$f['empresa_id']] = $f;
    }
    return $out;
}

/** Salud de uso: alta | media | baja | sin_uso (para priorizar seguimiento). */
function saludEmpresa(?array $act): string {
    if (!$act || !$act['ultima']) return 'sin_uso';
    $diasSinUso = (int)((time() - strtotime($act['ultima'])) / 86400);
    $eventos = (int)$act['eventos_30'];
    if ($diasSinUso > 30 || $eventos === 0) return 'sin_uso';
    if ($diasSinUso > 7 || $eventos < 30) return 'baja';
    if ($eventos < 150) return 'media';
    return 'alta';
}

/** Cartera completa con todo lo calculado (base de resumen, listado, cobranza y pruebas). */
function cartera(PDO $pdo): array {
    $actividad = actividadPorEmpresa($pdo);
    $pagados = $pdo->query("SELECT empresa_id, COUNT(*) AS pagos, SUM(monto) AS total FROM pagos WHERE anulado = 0 GROUP BY empresa_id")
        ->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
    $usuarios = $pdo->query("SELECT empresa_id, COUNT(*) FROM usuarios WHERE empresa_id IS NOT NULL GROUP BY empresa_id")
        ->fetchAll(PDO::FETCH_KEY_PAIR);
    $solicitudes = contratacionDisponible($pdo)
        ? $pdo->query("SELECT empresa_id, COUNT(*) FROM solicitudes_pago WHERE estado = 'pendiente' GROUP BY empresa_id")->fetchAll(PDO::FETCH_KEY_PAIR)
        : [];
    $filas = [];
    foreach ($pdo->query("SELECT * FROM empresas ORDER BY creado_en DESC")->fetchAll(PDO::FETCH_ASSOC) as $e) {
        $id = (int)$e['id'];
        $sit = situacionEmpresa($e);
        $precio = precioEmpresa($pdo, $e);
        $act = $actividad[$id] ?? null;
        $marca = json_decode($e['marca'] ?? '', true) ?: [];
        $filas[] = [
            'id' => $id, 'slug' => $e['slug'], 'nombre' => $e['nombre'],
            'estado_guardado' => $e['estado'], 'estado' => $sit['estado'], 'acceso' => $sit['acceso'],
            'dias' => $sit['dias'], 'mensaje' => $sit['mensaje'],
            'prueba_hasta' => $e['prueba_hasta'], 'pagado_hasta' => $e['pagado_hasta'],
            'plan_id' => $e['plan_id'] ? (int)$e['plan_id'] : null, 'plan' => $precio['plan']['nombre'] ?? '—',
            'ciclo' => $precio['ciclo'], 'precio_ciclo' => $precio['por_ciclo'], 'precio_pactado' => $precio['pactado'],
            'mrr' => in_array($sit['estado'], ESTADOS_CON_INGRESO, true) ? $precio['mensual'] : 0,
            'contacto_nombre' => $e['contacto_nombre'], 'contacto_correo' => $e['contacto_correo'],
            'contacto_telefono' => $e['contacto_telefono'], 'rfc' => $e['rfc'], 'razon_social' => $e['razon_social'],
            'creado_en' => $e['creado_en'], 'usuarios' => (int)($usuarios[$id] ?? 0),
            'ultima_actividad' => $act['ultima'] ?? null, 'eventos_30' => (int)($act['eventos_30'] ?? 0),
            'salud' => $e['estado'] === 'demo' ? 'demo' : saludEmpresa($act),
            'pagos' => (int)($pagados[$id]['pagos'] ?? 0), 'total_pagado' => (float)($pagados[$id]['total'] ?? 0),
            'autoservicio' => $e['registro_ip'] !== null,
            'solicitud_pendiente' => (int)($solicitudes[$id] ?? 0) > 0,
            'verificacion_hasta' => $e['verificacion_hasta'] ?? null,
            'color' => $marca['colores']['primario'] ?? null,
        ];
    }
    return $filas;
}

function serieMensual(PDO $pdo, string $sql): array {
    $datos = $pdo->query($sql)->fetchAll(PDO::FETCH_KEY_PAIR);
    $serie = [];
    for ($i = 11; $i >= 0; $i--) {
        $mes = date('Y-m', strtotime(date('Y-m-01') . " -$i months"));
        $serie[] = ['mes' => $mes, 'valor' => round((float)($datos[$mes] ?? 0), 2)];
    }
    return $serie;
}

function pagosEntre(PDO $pdo, string $desde, string $hasta): array {
    $stmt = $pdo->prepare(
        "SELECT p.*, e.nombre AS empresa, e.rfc, e.razon_social
         FROM pagos p LEFT JOIN empresas e ON e.id = p.empresa_id
         WHERE p.fecha_pago BETWEEN ? AND ? ORDER BY p.fecha_pago DESC, p.id DESC"
    );
    $stmt->execute([$desde, $hasta]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function fechaValida(string $f): bool {
    return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $f) && strtotime($f);
}

$accion = $_GET['accion'] ?? '';
$metodo = $_SERVER['REQUEST_METHOD'];
$body = $metodo === 'POST' ? (json_decode(file_get_contents('php://input'), true) ?: []) : [];
$id = (int)($_GET['id'] ?? $body['id'] ?? 0);

// D-50: en modo satélite la cobranza, los planes y el ciclo de vida se administran en Solid Admin.
// Aquí quedan soporte, notas, marca, configuración y cuentas de plataforma.
const ACCIONES_SOLID_ADMIN = ['crear', 'datos', 'plan', 'pago', 'anular_pago', 'confirmar_solicitud', 'rechazar_solicitud',
    'extender_prueba', 'estado', 'eliminar', 'guardar_plan'];
if ($metodo === 'POST' && solidSatelite() && in_array($accion, ACCIONES_SOLID_ADMIN, true)) {
    falla('Esta instancia de Servidesk se administra desde Solid Admin (clientes, planes, pagos y vigencias).', 409);
}

try {
    switch ("$metodo:$accion") {

    // ================================================================ lectura
    case 'GET:resumen':
        $c = cartera($pdo);
        $clientes = array_values(array_filter($c, fn($e) => $e['estado_guardado'] !== 'demo'));
        $pagando = array_filter($clientes, fn($e) => in_array($e['estado'], ESTADOS_CON_INGRESO, true));
        $mrr = array_sum(array_column($pagando, 'mrr'));
        $enPrueba = array_filter($clientes, fn($e) => $e['estado'] === 'prueba');
        $iniciadas = array_filter($clientes, fn($e) => $e['prueba_hasta'] !== null);
        $convertidas = array_filter($iniciadas, fn($e) => $e['pagos'] > 0);
        $cerradas = array_filter($iniciadas, fn($e) => $e['pagos'] > 0 || $e['estado'] !== 'prueba');
        $mesActual = date('Y-m-01');
        $mesAnterior = date('Y-m-01', strtotime("$mesActual -1 month"));
        $suma = function (string $desde, string $hasta) use ($pdo) {
            $s = $pdo->prepare("SELECT COALESCE(SUM(monto), 0) FROM pagos WHERE anulado = 0 AND fecha_pago BETWEEN ? AND ?");
            $s->execute([$desde, $hasta]);
            return (float)$s->fetchColumn();
        };
        $porPlan = [];
        foreach ($pagando as $e) {
            $porPlan[$e['plan']] = ($porPlan[$e['plan']] ?? 0) + $e['mrr'];
        }
        $cancel30 = (int)$pdo->query("SELECT COUNT(*) FROM plataforma_eventos WHERE accion = 'estado' AND detalle LIKE '%\"a\":\"cancelada\"%' AND creado_en >= NOW() - INTERVAL 30 DAY")->fetchColumn();
        $actividad = $pdo->query(
            "SELECT ev.*, e.nombre AS empresa FROM plataforma_eventos ev LEFT JOIN empresas e ON e.id = ev.empresa_id
             ORDER BY ev.id DESC LIMIT 12"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($actividad as &$a) $a['detalle'] = json_decode($a['detalle'] ?? '', true);
        unset($a);
        ok(['resumen' => [
            'moneda' => configPlataforma('moneda'),
            'mrr' => round($mrr, 2), 'arr' => round($mrr * 12, 2),
            'clientes_pagando' => count($pagando),
            'arpa' => $pagando ? round($mrr / count($pagando), 2) : 0,
            'ingresos_mes' => $suma($mesActual, date('Y-m-t')),
            'ingresos_mes_anterior' => $suma($mesAnterior, date('Y-m-t', strtotime($mesAnterior))),
            'pruebas_activas' => count($enPrueba),
            'pruebas_por_vencer' => count(array_filter($enPrueba, fn($e) => $e['dias'] !== null && $e['dias'] <= 3)),
            'conversion' => $cerradas ? round(count($convertidas) * 100 / count($cerradas), 1) : null,
            'pruebas_iniciadas' => count($iniciadas), 'pruebas_convertidas' => count($convertidas),
            'con_atraso' => count(array_filter($clientes, fn($e) => in_array($e['estado'], ['gracia', 'solo_lectura'], true))),
            'por_cobrar' => round(array_sum(array_map(fn($e) => $e['precio_ciclo'],
                array_filter($clientes, fn($e) => in_array($e['estado'], ['gracia', 'solo_lectura'], true)))), 2),
            'suspendidas' => count(array_filter($clientes, fn($e) => in_array($e['estado'], ['suspendida', 'prueba_vencida'], true))),
            'cancelaciones_30' => $cancel30,
            'registros_30' => count(array_filter($clientes, fn($e) => strtotime($e['creado_en']) >= strtotime('-30 days'))),
            'total_empresas' => count($clientes),
            'por_estado' => array_count_values(array_column($clientes, 'estado')),
            'mrr_por_plan' => $porPlan,
            'solicitudes_pendientes' => count(array_filter($clientes, fn($e) => $e['solicitud_pendiente'])),
        ],
        'series' => [
            'ingresos' => serieMensual($pdo, "SELECT DATE_FORMAT(fecha_pago, '%Y-%m'), SUM(monto) FROM pagos WHERE anulado = 0 AND fecha_pago >= DATE_FORMAT(CURDATE() - INTERVAL 11 MONTH, '%Y-%m-01') GROUP BY 1"),
            'registros' => serieMensual($pdo, "SELECT DATE_FORMAT(creado_en, '%Y-%m'), COUNT(*) FROM empresas WHERE estado <> 'demo' AND creado_en >= DATE_FORMAT(CURDATE() - INTERVAL 11 MONTH, '%Y-%m-01') GROUP BY 1"),
        ],
        'actividad' => $actividad]);

    case 'GET:empresas':
        ok(['empresas' => cartera($pdo), 'planes' => listarPlanes($pdo)]);

    case 'GET:empresa':
        $e = empresaPorId($pdo, $id);
        if (!$e) falla('Empresa no encontrada', 404);
        $fila = current(array_filter(cartera($pdo), fn($x) => $x['id'] === $id));
        $conteos = [];
        foreach (['incidencias', 'clientes', 'inventario_articulos', 'ventas', 'padron_equipos'] as $t) {
            $conteos[$t] = contarTablaEmpresa($pdo, $id, $t);
        }
        $q = function (string $sql) use ($pdo, $id) {
            $s = $pdo->prepare($sql);
            $s->execute([$id]);
            return $s->fetchAll(PDO::FETCH_ASSOC);
        };
        $eventos = $q("SELECT * FROM plataforma_eventos WHERE empresa_id = ? ORDER BY id DESC LIMIT 100");
        foreach ($eventos as &$ev) $ev['detalle'] = json_decode($ev['detalle'] ?? '', true);
        unset($ev);
        ok([
            'empresa' => $fila + ['marca' => $e['marca'], 'precio' => precioEmpresa($pdo, $e)],
            'conteos' => $conteos,
            'almacenamiento' => almacenamientoEmpresa($id),
            'limite_usuarios' => limiteUsuariosEmpresa($pdo, $id),
            'usuarios' => $q("SELECT id, nombre, usuario, rol, fecha_creacion FROM usuarios WHERE empresa_id = ? ORDER BY id"),
            'pagos' => $q("SELECT * FROM pagos WHERE empresa_id = ? ORDER BY fecha_pago DESC, id DESC"),
            'notas' => $q("SELECT * FROM empresa_notas WHERE empresa_id = ? ORDER BY id DESC"),
            'eventos' => $eventos,
            'planes' => listarPlanes($pdo),
        ]);

    case 'GET:cobranza':
        $mes = preg_match('/^\d{4}-\d{2}$/', $_GET['mes'] ?? '') ? $_GET['mes'] : date('Y-m');
        $desde = "$mes-01";
        $hasta = date('Y-m-t', strtotime($desde));
        $clientes = array_filter(cartera($pdo), fn($e) => $e['estado_guardado'] !== 'demo');
        $porCobrar = array_values(array_filter($clientes, fn($e) => in_array($e['estado'], ['gracia', 'solo_lectura', 'suspendida'], true) && $e['estado_guardado'] === 'activa'));
        usort($porCobrar, fn($a, $b) => $b['dias'] <=> $a['dias']);
        $hoy = date('Y-m-d');
        $limite = date('Y-m-d', strtotime('+30 days'));
        $proximos = array_values(array_filter($clientes, fn($e) => $e['estado'] === 'activa' && $e['pagado_hasta'] && $e['pagado_hasta'] >= $hoy && $e['pagado_hasta'] <= $limite));
        usort($proximos, fn($a, $b) => strcmp($a['pagado_hasta'], $b['pagado_hasta']));
        $pagos = pagosEntre($pdo, $desde, $hasta);
        $vigentes = array_filter($pagos, fn($p) => !(int)$p['anulado']);
        ok([
            'mes' => $mes,
            'kpis' => [
                'cobrado' => round(array_sum(array_column($vigentes, 'monto')), 2),
                'pagos' => count($vigentes),
                'anulados' => count($pagos) - count($vigentes),
                'por_cobrar' => round(array_sum(array_column($porCobrar, 'precio_ciclo')), 2),
                'cuentas_por_cobrar' => count($porCobrar),
                'renovaciones_30' => round(array_sum(array_column($proximos, 'precio_ciclo')), 2),
                'cuentas_renovacion' => count($proximos),
                'sin_factura' => count(array_filter($vigentes, fn($p) => empty($p['factura']))),
            ],
            'por_cobrar' => $porCobrar, 'proximos' => $proximos, 'pagos' => $pagos,
        ]);

    case 'GET:pruebas':
        $clientes = array_filter(cartera($pdo), fn($e) => $e['estado_guardado'] !== 'demo');
        $activas = array_values(array_filter($clientes, fn($e) => $e['estado'] === 'prueba'));
        usort($activas, fn($a, $b) => $a['dias'] <=> $b['dias']);
        $vencidas = array_values(array_filter($clientes, fn($e) => $e['estado'] === 'prueba_vencida'));
        usort($vencidas, fn($a, $b) => strcmp((string)$b['prueba_hasta'], (string)$a['prueba_hasta']));
        $iniciadas = array_filter($clientes, fn($e) => $e['prueba_hasta'] !== null);
        $convertidas = array_filter($iniciadas, fn($e) => $e['pagos'] > 0);
        ok([
            'activas' => $activas, 'vencidas' => $vencidas,
            'embudo' => [
                'registros' => count($iniciadas),
                'con_uso' => count(array_filter($iniciadas, fn($e) => $e['eventos_30'] > 0 || $e['ultima_actividad'])),
                'convertidas' => count($convertidas),
                'en_curso' => count($activas),
                'perdidas' => count($vencidas) + count(array_filter($iniciadas, fn($e) => $e['estado'] === 'cancelada' && !$e['pagos'])),
            ],
        ]);

    case 'GET:solicitudes':
        if (!contratacionDisponible($pdo)) falla('Falta importar la migración 004 (database/migrations/004_solicitudes_pago.sql).', 409);
        $todas = ($_GET['estado'] ?? '') === 'todas';
        $porEmpresa = [];
        foreach (cartera($pdo) as $e) $porEmpresa[$e['id']] = $e;
        $filas = $pdo->query(
            "SELECT s.*, p.nombre AS plan, e.nombre AS empresa FROM solicitudes_pago s
             LEFT JOIN planes p ON p.id = s.plan_id LEFT JOIN empresas e ON e.id = s.empresa_id"
            . ($todas ? '' : " WHERE s.estado = 'pendiente'") . " ORDER BY s.estado = 'pendiente' DESC, s.id DESC LIMIT 200"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($filas as &$f) {
            $f['tiene_comprobante'] = !empty($f['comprobante']);
            unset($f['comprobante']);
            $e = $porEmpresa[(int)$f['empresa_id']] ?? null;
            $f['cuenta'] = $e ? array_intersect_key($e, array_flip(['estado', 'dias', 'color', 'prueba_hasta', 'pagado_hasta',
                'contacto_nombre', 'contacto_telefono', 'contacto_correo'])) : null;
        }
        unset($f);
        ok(['solicitudes' => $filas, 'regimenes' => REGIMENES_FISCALES, 'usos_cfdi' => USOS_CFDI,
            'con_datos_pago' => configPlataforma('pago_clabe') !== '' || configPlataforma('pago_instrucciones') !== '']);

    case 'GET:comprobante':
        $sol = contratacionDisponible($pdo) ? solicitudPorId($pdo, $id) : null;
        if (!$sol) falla('Solicitud no encontrada', 404);
        enviarComprobante($sol);

    case 'GET:planes':
        ok(['planes' => listarPlanes($pdo)]);

    case 'GET:bitacora':
        $where = [];
        $params = [];
        if (!empty($_GET['empresa_id'])) { $where[] = 'ev.empresa_id = ?'; $params[] = (int)$_GET['empresa_id']; }
        if (!empty($_GET['tipo'])) { $where[] = 'ev.accion = ?'; $params[] = $_GET['tipo']; }
        if (!empty($_GET['desde']) && fechaValida($_GET['desde'])) { $where[] = 'ev.creado_en >= ?'; $params[] = $_GET['desde'] . ' 00:00:00'; }
        $stmt = $pdo->prepare(
            "SELECT ev.*, e.nombre AS empresa FROM plataforma_eventos ev LEFT JOIN empresas e ON e.id = ev.empresa_id"
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . " ORDER BY ev.id DESC LIMIT 300"
        );
        $stmt->execute($params);
        $eventos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($eventos as &$ev) $ev['detalle'] = json_decode($ev['detalle'] ?? '', true);
        unset($ev);
        ok(['eventos' => $eventos,
            'tipos' => $pdo->query("SELECT DISTINCT accion FROM plataforma_eventos ORDER BY accion")->fetchAll(PDO::FETCH_COLUMN),
            'empresas' => $pdo->query("SELECT id, nombre FROM empresas ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC)]);

    case 'GET:config':
        $valores = [];
        foreach (array_keys(CONFIG_PLATAFORMA_DEFECTO) as $k) $valores[$k] = configPlataforma($k);
        $valores['registro_abierto_efectivo'] = registroAbierto();
        $valores['registro_max_por_ip_efectivo'] = registroMaxPorIp();
        ok(['config' => $valores, 'planes' => listarPlanes($pdo, true), 'satelite' => solidSatelite()]);

    case 'GET:admins':
        ok(['admins' => listarAdministradores($pdo), 'yo' => (int)$_SESSION['user_id']]);

    case 'GET:exportar':
        $empresa = empresaPorId($pdo, $id);
        if (!$empresa) falla('Empresa no encontrada', 404);
        $zip = exportarEmpresa($pdo, $id);
        header_remove('Content-Type');
        header('Content-Type: application/zip');
        header('Content-Length: ' . filesize($zip));
        header('Content-Disposition: attachment; filename="servidesk-' . $empresa['slug'] . '-' . date('Ymd') . '.zip"');
        header('Cache-Control: private, no-store');
        readfile($zip);
        @unlink($zip);
        exit;

    case 'GET:csv_empresas':
        enviarCsv('servidesk-empresas-' . date('Ymd') . '.csv', [
            'id' => 'ID', 'nombre' => 'Empresa', 'razon_social' => 'Razón social', 'rfc' => 'RFC', 'estado' => 'Situación',
            'plan' => 'Plan', 'ciclo' => 'Ciclo', 'precio_ciclo' => 'Precio por ciclo', 'mrr' => 'MRR',
            'prueba_hasta' => 'Prueba hasta', 'pagado_hasta' => 'Pagado hasta', 'total_pagado' => 'Total pagado',
            'usuarios' => 'Usuarios', 'salud' => 'Uso', 'contacto_nombre' => 'Contacto', 'contacto_correo' => 'Correo',
            'contacto_telefono' => 'Teléfono', 'creado_en' => 'Alta',
        ], array_filter(cartera($pdo), fn($e) => $e['estado_guardado'] !== 'demo'));
        exit;

    case 'GET:csv_pagos':
        $desde = fechaValida($_GET['desde'] ?? '') ? $_GET['desde'] : date('Y-m-01');
        $hasta = fechaValida($_GET['hasta'] ?? '') ? $_GET['hasta'] : date('Y-m-t');
        $filas = array_map(function ($p) {
            $p['estado_pago'] = (int)$p['anulado'] ? 'Anulado: ' . $p['anulado_motivo'] : 'Vigente';
            return $p;
        }, pagosEntre($pdo, $desde, $hasta));
        enviarCsv("servidesk-pagos-$desde-a-$hasta.csv", [
            'id' => 'Pago', 'fecha_pago' => 'Fecha', 'empresa' => 'Empresa', 'razon_social' => 'Razón social', 'rfc' => 'RFC',
            'monto' => 'Monto', 'cubre_hasta' => 'Cubre hasta', 'metodo' => 'Método', 'referencia' => 'Referencia',
            'factura' => 'Factura (CFDI)', 'estado_pago' => 'Estado', 'registrado_por' => 'Registró', 'notas' => 'Notas',
        ], $filas);
        exit;

    // ================================================================ escritura
    case 'POST:crear':
        $nuevo = crearEmpresa($pdo, [
            'nombre'            => $body['nombre'] ?? '',
            'estado'            => in_array($body['estado'] ?? '', ['prueba', 'activa'], true) ? $body['estado'] : 'prueba',
            'admin_nombre'      => trim((string)($body['admin_nombre'] ?? '')) ?: 'Administrador',
            'admin_usuario'     => $body['correo'] ?? '',
            'admin_password'    => $body['password'] ?? '',
            'admin_telefono'    => $body['telefono'] ?? '',
            'contacto_correo'   => $body['correo'] ?? '',
            'contacto_telefono' => $body['telefono'] ?? null,
            'plan_id'           => (int)($body['plan_id'] ?? 0) ?: null,
            'ciclo'             => $body['ciclo'] ?? 'mensual',
        ]);
        registrarEventoPlataforma($pdo, $nuevo, 'alta_manual', ['nombre' => $body['nombre'] ?? '', 'estado' => $body['estado'] ?? 'prueba']);
        ok(['id' => $nuevo]);

    case 'POST:datos':
        actualizarDatosEmpresa($pdo, $id, $body);
        ok();

    case 'POST:plan':
        cambiarPlanEmpresa($pdo, $id, (int)($body['plan_id'] ?? 0), (string)($body['ciclo'] ?? 'mensual'), $body['precio'] ?? null);
        ok();

    case 'POST:pago':
        ok(registrarPago($pdo, $id, $body));

    case 'POST:anular_pago':
        anularPago($pdo, (int)($body['pago_id'] ?? 0), (string)($body['motivo'] ?? ''));
        ok();

    case 'POST:confirmar_solicitud':
        ok(confirmarSolicitud($pdo, $id, $body));

    case 'POST:rechazar_solicitud':
        rechazarSolicitud($pdo, $id, (string)($body['motivo'] ?? ''));
        ok();

    case 'POST:extender_prueba':
        ok(['prueba_hasta' => extenderPrueba($pdo, $id, (int)($body['dias'] ?? 0))]);

    case 'POST:estado':
        cambiarEstadoEmpresa($pdo, $id, (string)($body['estado'] ?? ''));
        ok();

    case 'POST:nota':
        agregarNotaEmpresa($pdo, $id, (string)($body['nota'] ?? ''));
        ok();

    case 'POST:marca':
        guardarMarcaEmpresa($pdo, $id, array_intersect_key($body, array_flip(['nombre', 'eslogan', 'prefijo_folio', 'zona_horaria', 'colores', 'categorias'])));
        registrarEventoPlataforma($pdo, $id, 'marca', array_intersect_key($body, array_flip(['nombre', 'eslogan', 'prefijo_folio', 'zona_horaria', 'colores'])));
        ok();

    case 'POST:soporte':
        iniciarSoporte($pdo, $id);
        ok(['redirect' => '../index.html']);

    case 'POST:eliminar':
        $empresa = empresaPorId($pdo, $id);
        if (!$empresa) falla('Empresa no encontrada', 404);
        if ($empresa['estado'] !== 'cancelada') falla('Solo se pueden eliminar empresas canceladas. Cancélala primero.');
        if (($body['confirmacion'] ?? '') !== $empresa['slug']) falla('Para confirmar escribe exactamente: ' . $empresa['slug']);
        eliminarEmpresa($pdo, $id);
        $pdo->prepare("DELETE FROM empresa_notas WHERE empresa_id = ?")->execute([$id]);
        registrarEventoPlataforma($pdo, null, 'eliminacion', ['empresa_id' => $id, 'nombre' => $empresa['nombre'], 'slug' => $empresa['slug']]);
        ok();

    case 'POST:guardar_plan':
        ok(['id' => guardarPlan($pdo, $body)]);

    case 'POST:guardar_config':
        guardarConfigPlataforma($pdo, $body);
        ok();

    case 'POST:admin_crear':
        ok(['id' => crearAdministrador($pdo, (string)($body['nombre'] ?? ''), (string)($body['correo'] ?? ''), (string)($body['password'] ?? ''))]);

    case 'POST:admin_eliminar':
        eliminarAdministrador($pdo, $id);
        ok();

    case 'POST:mi_password':
        cambiarPasswordAdministrador($pdo, (string)($body['actual'] ?? ''), (string)($body['nueva'] ?? ''));
        ok();

    default:
        falla('Acción no válida');
    }
} catch (InvalidArgumentException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    falla($e->getMessage(), 422);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Panel de plataforma: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    falla('Error interno del panel.', 500);
}
