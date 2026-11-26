<?php
// Datos del inicio (index.html) y de la navegación (scripts/menu-lateral.js).
//
// GET ?accion=panel        indicadores, bandeja de trabajo, carga por técnico,
//                          avisos y últimos cambios; para el rol Técnico, "Mi día"
// GET ?accion=contadores   incidencias activas (contador del menú lateral)
// GET ?accion=buscar&q=    buscador global: incidencias, clientes y equipos
//
// Cada bloque respeta los permisos del rol (permisos_rol): sin el módulo de
// incidencias no se devuelve nada de incidencias, etc.
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../auth/permisos.php';
require_once __DIR__ . '/lib/estadisticas_helpers.php';

function responder(array $datos): void {
    echo json_encode(['exito' => true] + $datos, JSON_UNESCAPED_UNICODE);
    exit;
}

// Activa = cualquier estatus salvo Cerrado (con o sin factura) y Cancelado (D-4)
require_once __DIR__ . '/lib/incidencias_helpers.php';
define('SQL_ACTIVA', sqlOrdenActiva());
// Atrasada = más días sin cerrar que la meta de cumplimiento de la empresa
define('DIAS_ATRASO', slaDiasEmpresa());
// D-33: aviso de trabajos completados que llevan más de estos días sin cierre administrativo
const DIAS_POR_CERRAR = 5;
// Órdenes activas que se mandan a la bandeja de Inicio (N-H08)
const BANDEJA_MAX = 1000;

/**
 * Días desde la última vez que cada orden Completado pasó a Completado (historial).
 * Sin historial se usa la fecha de reporte. Devuelve [id => días].
 */
function diasDesdeCompletada(PDO $pdo, array $filas): array {
    $ids = array_map(fn($f) => (string)$f['id'], array_filter($filas, fn($f) => $f['estatus'] === 'Completado'));
    if (!$ids) return [];
    $dias = [];
    foreach ($filas as $f) if ($f['estatus'] === 'Completado') $dias[(int)$f['id']] = (int)$f['dias'];
    try {
        $st = $pdo->prepare("SELECT registro_id, DATEDIFF(CURDATE(), MAX(creado_en)) AS dias FROM auditoria
            WHERE tabla = 'incidencias' AND registro_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")
              AND JSON_UNQUOTE(JSON_EXTRACT(datos_nuevos, '$.estatus')) = 'Completado'
              AND (datos_anteriores IS NULL OR COALESCE(JSON_UNQUOTE(JSON_EXTRACT(datos_anteriores, '$.estatus')), '') <> 'Completado')
            GROUP BY registro_id");
        $st->execute(array_values($ids));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $dias[(int)$r['registro_id']] = max(0, (int)$r['dias']);
    } catch (Throwable $e) {
        // Sin historial legible se queda la fecha de reporte
    }
    return $dias;
}

/** Nombre con el que el usuario aparece en incidencias.tecnico (ver estadisticas_tecnico.php). */
function identidadTecnico(PDO $pdo): string {
    return identidadTecnicoSesion($pdo);  // una sola identidad para todos los endpoints (D-9)
    $identidad = trim($_SESSION['nombre'] ?? '');
    try {
        $stmt = $pdo->prepare("SELECT alias_tecnico FROM usuarios WHERE id = ? LIMIT 1");
        $stmt->execute([$_SESSION['user_id'] ?? 0]);
        $alias = trim((string)$stmt->fetchColumn());
        if ($alias !== '') $identidad = $alias;
    } catch (Throwable $e) {
        // Sin la columna alias_tecnico se usa el nombre
    }
    return $identidad;
}

function incidenciasActivas(PDO $pdo): array {
    return $pdo->query(
        "SELECT id, numero_incidente, cliente, sucursal, falla, tecnico, estatus, fecha,
                GREATEST(DATEDIFF(CURDATE(), fecha), 0) AS dias
         FROM incidencias WHERE " . SQL_ACTIVA . " ORDER BY fecha ASC, id ASC"
    )->fetchAll(PDO::FETCH_ASSOC);
}

/** Recorta la falla para la bandeja (la lista completa vive en el módulo). */
function filaBandeja(array $f): array {
    $f['id'] = (int)$f['id'];
    $f['dias'] = (int)$f['dias'];
    $f['falla'] = mb_strimwidth(trim((string)$f['falla']), 0, 90, '…');
    $f['sin_tecnico'] = count(separarTecnicos($f['tecnico'] ?? '')) === 0;
    return $f;
}

$accion = $_GET['accion'] ?? 'panel';
$verIncidencias = tienePermiso('incidencias');
// D-9: contador y buscador del Técnico solo cuentan sus órdenes
require_once __DIR__ . '/lib/incidencias_helpers.php';
[$soloMias, $paramsMias] = filtroOrdenesSesion($pdo);

try {
    switch ($accion) {

    case 'contadores':
        responder(['incidencias' => $verIncidencias
            ? (int)(function () use ($pdo, $soloMias, $paramsMias) {
                $st = $pdo->prepare("SELECT tecnico FROM incidencias WHERE " . SQL_ACTIVA . " AND $soloMias");
                $st->execute($paramsMias);
                return count(filtrarOrdenesVisibles($pdo, $st->fetchAll(PDO::FETCH_ASSOC)));
            })() : null]);

    case 'buscar':
        $q = trim((string)($_GET['q'] ?? ''));
        if (mb_strlen($q) < 2) responder(['incidencias' => [], 'cotizaciones' => [], 'clientes' => [], 'equipos' => []]);
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
        $res = ['incidencias' => [], 'cotizaciones' => [], 'clientes' => [], 'equipos' => []];
        if ($verIncidencias) {
            $stmt = $pdo->prepare(
                "SELECT id, numero_incidente, cliente, sucursal, falla, estatus, tecnico FROM incidencias
                 WHERE (numero_incidente LIKE ? OR cliente LIKE ? OR sucursal LIKE ? OR numero_serie LIKE ? OR reporte_cliente LIKE ?) AND $soloMias
                 ORDER BY id DESC LIMIT 8"
            );
            $stmt->execute(array_merge([$like, $like, $like, $like, $like], $paramsMias));
            $res['incidencias'] = array_map(function ($f) { unset($f['tecnico']); return ['falla' => mb_strimwidth((string)$f['falla'], 0, 70, '…')] + $f; },
                filtrarOrdenesVisibles($pdo, $stmt->fetchAll(PDO::FETCH_ASSOC)));
        }
        if (tienePermiso('cotizaciones')) {
            try {
                $stmt = $pdo->prepare("SELECT id, folio, cliente_nombre, sucursal, total, estatus FROM cotizaciones
                                       WHERE reemplazada_por IS NULL AND (folio LIKE ? OR cliente_nombre LIKE ?) ORDER BY id DESC LIMIT 5");
                $stmt->execute([$like, $like]);
                $res['cotizaciones'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {
                // Sin la migración 007
            }
        }
        if (tienePermiso('clientes')) {
            $stmt = $pdo->prepare("SELECT id, nombre, rfc FROM clientes WHERE nombre LIKE ? OR rfc LIKE ? ORDER BY nombre LIMIT 5");
            $stmt->execute([$like, $like]);
            $res['clientes'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            try {
                $stmt = $pdo->prepare(
                    "SELECT p.id, p.equipo, p.marca, p.modelo, p.numero_serie, p.cliente, p.sucursal, c.id AS cliente_id
                     FROM padron_equipos p LEFT JOIN clientes c ON c.nombre = p.cliente
                     WHERE p.numero_serie LIKE ? OR p.modelo LIKE ? ORDER BY p.id DESC LIMIT 5"
                );
                $stmt->execute([$like, $like]);
                $res['equipos'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {
                // Empresa sin padrón de equipos
            }
        }
        responder($res);

    case 'panel':
    default:
        $rol = $_SESSION['rol'] ?? '';
        $datos = [
            'usuario' => ['nombre' => $_SESSION['nombre'] ?? '', 'rol' => $rol],
            'vista' => $rol === 'Técnico' ? 'tecnico' : 'operacion',
            'permisos' => ['incidencias' => $verIncidencias, 'ventas' => tienePermiso('ventas'), 'historial' => tienePermiso('historial')],
            'dias_atraso' => DIAS_ATRASO,
        ];
        if (!$verIncidencias) responder($datos);

        $activas = array_map('filaBandeja', incidenciasActivas($pdo));
        // D-42: próximas programadas (fecha futura), visibles para todos con D-9
        $st = $pdo->prepare("SELECT id, numero_incidente, cliente, sucursal, falla, tecnico, estatus, fecha
                             FROM incidencias WHERE " . sqlOrdenProgramada() . " AND $soloMias ORDER BY fecha ASC, id ASC LIMIT 50");
        $st->execute($paramsMias);
        $datos['programadas'] = array_map(fn($f) => ['id' => (int)$f['id'], 'falla' => mb_strimwidth(trim((string)$f['falla']), 0, 90, '…')] + $f,
            array_slice(filtrarOrdenesVisibles($pdo, $st->fetchAll(PDO::FETCH_ASSOC)), 0, 10));

        if ($datos['vista'] === 'tecnico') {
            $yo = identidadTecnico($pdo);
            $mias = array_values(array_filter($activas, fn($f) => tecnicoCoincide($yo, $f['tecnico'] ?? '')));
            // Lo más antiguo primero: es lo que más urge atender
            usort($mias, fn($a, $b) => [$b['estatus'] !== 'Completado', $b['dias']] <=> [$a['estatus'] !== 'Completado', $a['dias']]);
            $datos['indicadores'] = [
                'asignadas'  => count(array_filter($mias, fn($f) => in_array($f['estatus'], ['Abierto', 'Asignado', 'En proceso'], true))),
                'pendientes' => count(array_filter($mias, fn($f) => $f['estatus'] === 'Pendiente')),
                'atrasadas'  => count(array_filter($mias, fn($f) => ordenAtrasada($f['fecha'], $f['estatus']))),  // D-18
                'completadas'=> count(array_filter($mias, fn($f) => $f['estatus'] === 'Completado')),
            ];
            $datos['bandeja'] = array_slice($mias, 0, BANDEJA_MAX);  // N-H08
            responder($datos);
        }

        $sinCerrar = array_filter($activas, fn($f) => $f['estatus'] !== 'Completado');
        $atrasadas = array_filter($sinCerrar, fn($f) => ordenAtrasada($f['fecha'], $f['estatus']));  // D-18
        $sinTecnico = array_filter($sinCerrar, fn($f) => $f['sin_tecnico']);
        $datos['indicadores'] = [
            'abiertas'    => count(array_filter($activas, fn($f) => $f['estatus'] === 'Abierto' || $f['estatus'] === null || $f['estatus'] === '')),
            'asignadas'   => count(array_filter($activas, fn($f) => in_array($f['estatus'], ['Asignado', 'En proceso'], true))),
            'en_proceso'  => count(array_filter($activas, fn($f) => $f['estatus'] === 'En proceso')),
            'pendientes'  => count(array_filter($activas, fn($f) => $f['estatus'] === 'Pendiente')),
            'atrasadas'   => count($atrasadas),
            'completadas' => count(array_filter($activas, fn($f) => $f['estatus'] === 'Completado')),
            'sin_tecnico' => count($sinTecnico),
            'hoy'         => count(array_filter($activas, fn($f) => $f['fecha'] === date('Y-m-d'))),
        ];

        // Bandeja: lo urgente arriba (sin técnico y lo más antiguo); lo completado al final
        $bandeja = $activas;
        usort($bandeja, fn($a, $b) =>
            [$a['estatus'] === 'Completado', !$a['sin_tecnico'], -$a['dias']] <=> [$b['estatus'] === 'Completado', !$b['sin_tecnico'], -$b['dias']]);
        // N-H08: la bandeja trae todas las activas (hasta BANDEJA_MAX) para que los filtros
        // coincidan con los indicadores; si se recorta, se avisa
        $datos['bandeja'] = array_slice($bandeja, 0, BANDEJA_MAX);
        $datos['bandeja_recortada'] = count($bandeja) > BANDEJA_MAX ? count($bandeja) : 0;

        // Carga por técnico (una incidencia con dos técnicos cuenta para ambos)
        $carga = [];
        foreach ($sinCerrar as $f) {
            foreach (separarTecnicos($f['tecnico'] ?? '') as $t) $carga[$t] = ($carga[$t] ?? 0) + 1;
        }
        arsort($carga);
        $datos['carga'] = array_map(fn($n, $c) => ['tecnico' => $n, 'total' => $c], array_keys($carga), $carga);
        $datos['carga_sin_tecnico'] = count($sinTecnico);

        // Avisos: lo que necesita una decisión hoy
        $avisos = [];
        if ($atrasadas) {
            $vieja = array_reduce($atrasadas, fn($m, $f) => !$m || $f['dias'] > $m['dias'] ? $f : $m);
            $avisos[] = ['tipo' => 'peligro', 'filtro' => 'atrasadas', 'titulo' => count($atrasadas) . ' con más de ' . DIAS_ATRASO . ' días sin cerrar',
                         'detalle' => 'La más antigua: ' . ($vieja['numero_incidente'] ?: '#' . $vieja['id']) . ' (' . $vieja['dias'] . ' días)'];
        }
        // D-33: completadas que llevan más de DIAS_POR_CERRAR días sin cierre administrativo
        $diasCompletada = diasDesdeCompletada($pdo, $activas);
        $porCerrar = array_filter($diasCompletada, fn($d) => $d > DIAS_POR_CERRAR);
        foreach ($datos['bandeja'] as &$fb) {
            if (isset($diasCompletada[$fb['id']])) $fb['dias_completada'] = $diasCompletada[$fb['id']];
        }
        unset($fb);
        if ($porCerrar) {
            $avisos[] = ['tipo' => 'alerta', 'filtro' => 'por_cerrar',
                         'titulo' => count($porCerrar) . (count($porCerrar) === 1 ? ' completada' : ' completadas') . ' con más de ' . DIAS_POR_CERRAR . ' días sin cerrar',
                         'detalle' => 'El técnico ya terminó: falta el cierre administrativo (con o sin factura)'];
        }
        if ($sinTecnico) {
            $avisos[] = ['tipo' => 'info', 'filtro' => 'sin_tecnico', 'titulo' => count($sinTecnico) . ' sin técnico asignado', 'detalle' => 'Asígnalas para que alguien las atienda'];
        }
        // N-H05: el aviso del padrón (con enlace a Clientes) solo para quien tiene ese módulo.
        // N-H09: la fecha que cuenta es la del recordatorio activo, como en el generador de tickets.
        $proxima = "CASE WHEN COALESCE(servicio, 0) = 1 AND frecuencia_servicio > 0 THEN proximo_servicio
                         WHEN COALESCE(servicio, 0) = 0 AND calibracion > 0 THEN proxima_calibracion END";
        if (tienePermiso('clientes')) try {
            $stmt = $pdo->query(
                "SELECT COUNT(*) AS total, SUM(($proxima) < CURDATE()) AS vencidos
                 FROM padron_equipos
                 WHERE ($proxima) <= CURDATE() + INTERVAL 7 DAY"
            );
            $eq = $stmt->fetch(PDO::FETCH_ASSOC);
            if ((int)$eq['total'] > 0) {
                $avisos[] = ['tipo' => 'alerta', 'enlace' => 'public/clientes.html', 'titulo' => (int)$eq['total'] . ' equipos con mantenimiento por vencer',
                             'detalle' => ((int)$eq['vencidos'] ? (int)$eq['vencidos'] . ' ya vencidos · ' : '') . 'próximos 7 días, según el padrón'];
            }
        } catch (Throwable $e) {
            // Empresa sin padrón de equipos
        }
        $datos['avisos'] = $avisos;

        // N-H05: "Últimos cambios" solo de los módulos que el rol puede ver
        $tablasCambios = array_keys(array_filter(['incidencias' => $verIncidencias, 'ventas' => tienePermiso('ventas'), 'clientes' => tienePermiso('clientes')]));
        if ($datos['permisos']['historial'] && $tablasCambios) {
            $st = $pdo->prepare(
                "SELECT usuario_nombre, accion, tabla, registro_id, registro_folio, creado_en,
                        GREATEST(TIMESTAMPDIFF(MINUTE, creado_en, NOW()), 0) AS hace_min FROM auditoria
                 WHERE tabla IN (" . implode(',', array_fill(0, count($tablasCambios), '?')) . ") ORDER BY id DESC LIMIT 6"
            );
            $st->execute($tablasCambios);
            $datos['cambios'] = $st->fetchAll(PDO::FETCH_ASSOC);
        }
        responder($datos);
    }
} catch (Throwable $e) {
    error_log('Inicio: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode(['exito' => false, 'mensaje' => 'No se pudo cargar el panel. Recarga la página.'], JSON_UNESCAPED_UNICODE);
}
