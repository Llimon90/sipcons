<?php
// Datos del dashboard de Inicio (index.html).
//
// GET ?accion=panel        indicadores, bandeja de trabajo, carga por técnico,
//                          avisos y últimos cambios; para el rol Técnico, "Mi día"
// GET ?accion=contadores   incidencias activas visibles para el usuario (badge del menú lateral)
//
// Adaptado de Servidesk (producto hermano, multiempresa) a SIPCONS (una sola
// empresa): sin empresa_id ni tablas con prefijo. El buscador global ya vive
// en backend/buscar-global.php, no se repite aquí.
//
// Cada bloque respeta permisos_rol (auth/permisos.php): sin el módulo de
// incidencias no se devuelve nada de incidencias.
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../auth/permisos.php';
require_once __DIR__ . '/lib/incidencias_helpers.php';
require_once __DIR__ . '/lib/sla_helpers.php';
require_once __DIR__ . '/lib/estadisticas_helpers.php'; // separarTecnicos(), tecnicoCoincide()

function responder(array $datos): void {
    echo json_encode(['exito' => true] + $datos, JSON_UNESCAPED_UNICODE);
    exit;
}

// Activas = cualquier estatus salvo los de cierre (ESTATUS_DE_CIERRE, config/auth.php)
const ESTATUS_ACTIVOS_INICIO = ['Abierto', 'Asignado', 'Pendiente', 'Completado'];
// D-33 (Servidesk): aviso de trabajos completados con más de estos días sin cierre administrativo
const DIAS_POR_CERRAR = 5;

/** Nombre con el que el usuario en sesión aparece en incidencias.tecnico (alias_tecnico si existe). */
function identidadTecnicoSesionInicio(PDO $pdo): string {
    $identidad = trim($_SESSION['nombre'] ?? '');
    try {
        $stmt = $pdo->prepare("SELECT alias_tecnico FROM usuarios WHERE id = ? LIMIT 1");
        $stmt->execute([$_SESSION['user_id'] ?? 0]);
        $alias = trim((string)$stmt->fetchColumn());
        if ($alias !== '') $identidad = $alias;
    } catch (Throwable $e) {
        // Sin la columna alias_tecnico (migración 008) se usa el nombre de sesión
    }
    return $identidad;
}

function filaBandejaInicio(PDO $pdo, array $f): array {
    $f['id'] = (int)$f['id'];
    $f['dias'] = max(0, (int)$f['dias']);
    $f['falla'] = mb_strimwidth(trim((string)($f['falla'] ?? '')), 0, 90, '…');
    $f['sin_tecnico'] = count(separarTecnicos($f['tecnico'] ?? '')) === 0;
    $f['atrasada'] = $f['estatus'] !== 'Completado'
        && incidenciaAtrasada($pdo, $f['fecha'] ?? null, $f['categoria'] ?? null, $f['prioridad'] ?? null);
    return $f;
}

$accion = $_GET['accion'] ?? 'panel';
$verIncidencias = tienePermiso('incidencias');

try {
    switch ($accion) {

    case 'contadores':
        if (!$verIncidencias) responder(['incidencias' => null]);
        $esTecnico = ($_SESSION['rol'] ?? '') === 'Técnico';
        $filas = $pdo->query(
            "SELECT tecnico FROM incidencias WHERE estatus IN ('" . implode("','", ESTATUS_ACTIVOS_INICIO) . "')"
        )->fetchAll(PDO::FETCH_ASSOC);
        if (!$esTecnico) responder(['incidencias' => count($filas)]);
        $yo = identidadTecnicoSesionInicio($pdo);
        $mias = count(array_filter($filas, fn($f) => tecnicoCoincide($yo, $f['tecnico'] ?? '')));
        responder(['incidencias' => $mias]);

    case 'panel':
    default:
        $rol = $_SESSION['rol'] ?? '';
        $datos = [
            'usuario'  => ['nombre' => $_SESSION['nombre'] ?? '', 'rol' => $rol],
            'vista'    => $rol === 'Técnico' ? 'tecnico' : 'operacion',
            'permisos' => ['incidencias' => $verIncidencias, 'ventas' => tienePermiso('ventas'), 'clientes' => tienePermiso('clientes'), 'historial' => tienePermiso('historial')],
        ];
        if (!$verIncidencias) responder($datos);

        $st = $pdo->query(
            "SELECT id, numero_incidente, cliente, sucursal, categoria, prioridad, falla, tecnico, estatus, fecha,
                    GREATEST(DATEDIFF(CURDATE(), fecha), 0) AS dias
             FROM incidencias WHERE estatus IN ('" . implode("','", ESTATUS_ACTIVOS_INICIO) . "')"
        );
        $activas = array_map(fn($f) => filaBandejaInicio($pdo, $f), $st->fetchAll(PDO::FETCH_ASSOC));

        if ($datos['vista'] === 'tecnico') {
            $yo = identidadTecnicoSesionInicio($pdo);
            $mias = array_values(array_filter($activas, fn($f) => tecnicoCoincide($yo, $f['tecnico'] ?? '')));
            // Lo más urgente primero: atrasadas, luego lo más antiguo; completadas al final
            usort($mias, fn($a, $b) =>
                [$a['estatus'] === 'Completado', !$a['atrasada'], -$a['dias']] <=> [$b['estatus'] === 'Completado', !$b['atrasada'], -$b['dias']]);
            $datos['indicadores'] = [
                'asignadas'   => count(array_filter($mias, fn($f) => in_array($f['estatus'], ['Abierto', 'Asignado'], true))),
                'pendientes'  => count(array_filter($mias, fn($f) => $f['estatus'] === 'Pendiente')),
                'atrasadas'   => count(array_filter($mias, fn($f) => $f['atrasada'])),
                'completadas' => count(array_filter($mias, fn($f) => $f['estatus'] === 'Completado')),
            ];
            $datos['bandeja'] = $mias;
            responder($datos);
        }

        $sinCerrar = array_filter($activas, fn($f) => $f['estatus'] !== 'Completado');
        $atrasadas = array_filter($activas, fn($f) => $f['atrasada']);
        $sinTecnico = array_filter($sinCerrar, fn($f) => $f['sin_tecnico']);
        $datos['indicadores'] = [
            'abiertas'    => count(array_filter($activas, fn($f) => $f['estatus'] === 'Abierto')),
            'asignadas'   => count(array_filter($activas, fn($f) => $f['estatus'] === 'Asignado')),
            'pendientes'  => count(array_filter($activas, fn($f) => $f['estatus'] === 'Pendiente')),
            'completadas' => count(array_filter($activas, fn($f) => $f['estatus'] === 'Completado')),
            'atrasadas'   => count($atrasadas),
            'sin_tecnico' => count($sinTecnico),
            'hoy'         => count(array_filter($activas, fn($f) => $f['fecha'] === date('Y-m-d'))),
        ];

        // Bandeja: lo urgente arriba (atrasada y sin técnico), lo completado al final
        $bandeja = $activas;
        usort($bandeja, fn($a, $b) =>
            [$a['estatus'] === 'Completado', !$a['atrasada'], !$a['sin_tecnico'], -$a['dias']]
            <=> [$b['estatus'] === 'Completado', !$b['atrasada'], !$b['sin_tecnico'], -$b['dias']]);
        $datos['bandeja'] = $bandeja;

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
            $avisos[] = ['tipo' => 'peligro', 'filtro' => 'atrasadas',
                         'titulo' => count($atrasadas) . ' con el SLA de cierre vencido',
                         'detalle' => 'La más antigua: ' . ($vieja['numero_incidente'] ?: '#' . $vieja['id']) . ' (' . $vieja['dias'] . ' días)'];
        }
        if ($sinTecnico) {
            $avisos[] = ['tipo' => 'info', 'filtro' => 'sin_tecnico', 'titulo' => count($sinTecnico) . ' sin técnico asignado',
                         'detalle' => 'Asígnalas para que alguien las atienda'];
        }
        // D-33 (Servidesk): completadas que llevan más de DIAS_POR_CERRAR días sin cierre administrativo
        $completadasViejas = array_filter($activas, fn($f) => $f['estatus'] === 'Completado' && $f['dias'] > DIAS_POR_CERRAR);
        if ($completadasViejas) {
            $n = count($completadasViejas);
            $avisos[] = ['tipo' => 'alerta', 'filtro' => 'completadas',
                         'titulo' => $n . ($n === 1 ? ' completada' : ' completadas') . ' con más de ' . DIAS_POR_CERRAR . ' días sin cerrar',
                         'detalle' => 'El técnico ya terminó: falta el cierre administrativo (con o sin factura)'];
        }
        // Mantenimientos/pólizas del padrón por vencer en 7 días (mismo criterio que
        // backend/generador_tickets.php, que ya genera el ticket 10 días antes)
        if (tienePermiso('clientes')) {
            try {
                $proxima = "CASE WHEN COALESCE(servicio, 0) = 1 AND frecuencia_servicio > 0 THEN proximo_servicio
                                 WHEN COALESCE(servicio, 0) = 0 AND calibracion > 0 THEN proxima_calibracion END";
                $eq = $pdo->query(
                    "SELECT COUNT(*) AS total, SUM(($proxima) < CURDATE()) AS vencidos
                     FROM padron_equipos WHERE ($proxima) <= CURDATE() + INTERVAL 7 DAY"
                )->fetch(PDO::FETCH_ASSOC);
                if ((int)($eq['total'] ?? 0) > 0) {
                    $avisos[] = ['tipo' => 'alerta', 'enlace' => 'clientes.html', 'filtro' => null,
                                 'titulo' => (int)$eq['total'] . ' equipos con mantenimiento por vencer',
                                 'detalle' => ((int)($eq['vencidos'] ?? 0) ? (int)$eq['vencidos'] . ' ya vencidos · ' : '') . 'próximos 7 días, según el padrón'];
                }
            } catch (Throwable $e) {
                // Entorno sin padrón de equipos
            }
        }
        $datos['avisos'] = $avisos;

        // Últimos cambios (auditoría), solo de los módulos que el rol puede ver
        $tablasCambios = array_keys(array_filter(['incidencias' => $verIncidencias, 'ventas' => $datos['permisos']['ventas'], 'clientes' => $datos['permisos']['clientes']]));
        if ($datos['permisos']['historial'] && $tablasCambios) {
            $st = $pdo->prepare(
                "SELECT usuario_nombre, accion, tabla, registro_id, registro_folio, creado_en,
                        GREATEST(TIMESTAMPDIFF(MINUTE, creado_en, NOW()), 0) AS hace_min
                 FROM auditoria WHERE tabla IN (" . implode(',', array_fill(0, count($tablasCambios), '?')) . ")
                 ORDER BY id DESC LIMIT 6"
            );
            $st->execute($tablasCambios);
            $datos['cambios'] = $st->fetchAll(PDO::FETCH_ASSOC);
        }
        responder($datos);
    }
} catch (Throwable $e) {
    error_log('inicio.php: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode(['exito' => false, 'mensaje' => 'No se pudo cargar el panel. Recarga la página.'], JSON_UNESCAPED_UNICODE);
}
