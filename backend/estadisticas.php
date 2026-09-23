<?php
require_once __DIR__ . '/../auth/middleware.php';

// --- DEBUG (COMENTAR EN PRODUCCIÓN PARA NO ROMPER EL JSON) ---
// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL);
// ---------------------------------

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// --- 1. CONEXIÓN DE BASE DE DATOS ---
// $conn (mysqli) y $pdo (PDO) ya los provee auth/middleware.php (config/database.php)

if (!isset($conn) || $conn->connect_error) {
    echo json_encode([
        'success' => false,
        'error' => 'Error al cargar la conexión a la base de datos. Por favor, revisa conexion.php.'
    ]);
    exit();
}

// Nombre de la tabla de incidencias
$tabla_incidencias = "incidencias";

// Umbrales de SLA (mismo criterio de 7 días que ya usa backend/alertas_incidentes.php)
const SLA_RESPUESTA_HORAS = 4;
const SLA_CIERRE_HORAS = 24 * 7;

// Estatus considerados "cierre" (coincide con la definición de "resueltas" ya usada en el panel)
const ESTADOS_CERRADOS = ['completado', 'cerrado con factura', 'cerrado sin factura', 'resuelto'];
// Estatus considerados "sin tocar todavía" (para medir primera respuesta)
const ESTADOS_SIN_RESPUESTA = ['abierto', ''];

// --- 2. FUNCIONES DE UTILIDAD ---

/**
 * Recopila y sanea los parámetros de filtro de la URL.
 * Si se pasan $fechaInicioOverride/$fechaFinOverride, se usan esas fechas
 * en vez de leer rangoFecha/fechaInicio/fechaFin de la URL (para poder
 * calcular el período anterior equivalente y sacar una tendencia real).
 * Devuelve el WHERE listo para usar y las fechas efectivas que se aplicaron.
 */
function construirFiltros($conn, $tabla_alias = 'i', $campo_fecha = 'fecha', $fechaInicioOverride = null, $fechaFinOverride = null) {
    $filtros = [];

    $tecnico = $_GET['tecnico'] ?? '';
    $sucursal = $_GET['sucursal'] ?? '';
    $estatus = $_GET['estatus'] ?? '';

    $campo_fecha_db = $tabla_alias . "." . $campo_fecha;

    if ($fechaInicioOverride !== null && $fechaFinOverride !== null) {
        $fecha_inicio = $fechaInicioOverride;
        $fecha_fin = $fechaFinOverride;
    } else {
        $rango = $_GET['rangoFecha'] ?? '30';
        $fecha_actual = new DateTime();
        $fecha_fin = $fecha_actual->format('Y-m-d');

        if ($rango === 'custom' && !empty($_GET['fechaInicio']) && !empty($_GET['fechaFin'])) {
            $fecha_inicio = $_GET['fechaInicio'];
            $fecha_fin = $_GET['fechaFin'];
        } else {
            $dias = intval($rango);
            if ($dias <= 0) $dias = 30;
            $fecha_inicio = (clone $fecha_actual)->modify("-$dias days")->format('Y-m-d');
        }
    }

    $filtros[] = "{$campo_fecha_db} BETWEEN '{$conn->real_escape_string($fecha_inicio)} 00:00:00' AND '{$conn->real_escape_string($fecha_fin)} 23:59:59'";

    // Filtros por selección (comparación case-insensitive: los valores reales
    // en BD están capitalizados, p.ej. "Cerrado con factura", pero no hay que
    // depender de la collation de la tabla para que el filtro funcione)
    if (!empty($tecnico)) {
        $valor = $conn->real_escape_string($tecnico);
        $filtros[] = "{$tabla_alias}.tecnico LIKE '%{$valor}%'";
    }

    if (!empty($sucursal)) {
        $valor = $conn->real_escape_string($sucursal);
        $filtros[] = "LOWER({$tabla_alias}.sucursal) = LOWER('{$valor}')";
    }

    if (!empty($estatus)) {
        $valor = $conn->real_escape_string($estatus);
        $filtros[] = "LOWER({$tabla_alias}.estatus) = LOWER('{$valor}')";
    }

    return [
        'where' => empty($filtros) ? "" : "WHERE " . implode(" AND ", $filtros),
        'fecha_inicio' => $fecha_inicio,
        'fecha_fin' => $fecha_fin,
    ];
}

/**
 * Ejecuta una consulta y devuelve los resultados como un array asociativo.
 */
function ejecutarConsulta($conn, $sql) {
    $resultado = $conn->query($sql);
    $data = [];
    if ($resultado === false) {
        error_log("Error SQL: " . $conn->error . "\nConsulta: " . $sql);
        return [];
    }
    if ($resultado->num_rows > 0) {
        while ($fila = $resultado->fetch_assoc()) {
            $data[] = $fila;
        }
    }
    return $data;
}

function promedio(array $valores) {
    $n = count($valores);
    return $n > 0 ? array_sum($valores) / $n : null;
}

function mediana(array $valores) {
    $n = count($valores);
    if ($n === 0) return null;
    sort($valores);
    $mitad = intdiv($n, 2);
    if ($n % 2 === 0) {
        return ($valores[$mitad - 1] + $valores[$mitad]) / 2;
    }
    return $valores[$mitad];
}

/**
 * Separa el campo "tecnico" (texto libre, técnicos separados por "/") en
 * nombres individuales limpios.
 */
function separarTecnicos($valorTecnico) {
    $tecnicos = [];
    foreach (explode('/', (string)$valorTecnico) as $t) {
        $t = trim($t);
        if (strlen($t) > 2) {
            $tecnicos[] = $t;
        }
    }
    return array_values(array_unique($tecnicos));
}

/**
 * Calcula, a partir del historial real en `auditoria`, el tiempo de primera
 * respuesta y el tiempo de cierre de cada incidencia del conjunto filtrado,
 * en una sola consulta (nada de N+1). También agrega métricas globales y
 * por técnico.
 *
 * $filas: cada elemento debe traer al menos id, fecha, estatus y (opcional) tecnico.
 */
function analizarTiemposIncidencias(PDO $pdo, array $filas) {
    $vacio = [
        'por_incidencia' => [],
        'agregado' => [
            'respuesta_promedio_horas' => null,
            'respuesta_mediana_horas' => null,
            'muestras_respuesta' => 0,
            'cierre_promedio_horas' => null,
            'cierre_mediana_horas' => null,
            'muestras_cierre' => 0,
            'sla_respuesta_pct' => null,
            'sla_cierre_pct' => null,
            'reabiertas' => 0,
            'total_incidencias' => count($filas),
            'cierre_buckets' => [
                ['label' => '< 1 día', 'cantidad' => 0],
                ['label' => '1-3 días', 'cantidad' => 0],
                ['label' => '3-7 días', 'cantidad' => 0],
                ['label' => '> 7 días', 'cantidad' => 0],
            ],
        ],
        'por_tecnico' => [],
    ];

    if (empty($filas)) {
        return $vacio;
    }

    $ids = array_map(fn($f) => (string)$f['id'], $filas);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $stmt = $pdo->prepare("
        SELECT registro_id, datos_nuevos, creado_en
        FROM auditoria
        WHERE tabla = 'incidencias' AND registro_id IN ($placeholders)
        ORDER BY registro_id, creado_en ASC, id ASC
    ");
    $stmt->execute($ids);
    $eventos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $eventosPorId = [];
    foreach ($eventos as $ev) {
        $eventosPorId[$ev['registro_id']][] = $ev;
    }

    $tiemposRespuesta = [];
    $tiemposCierre = [];
    $reabiertas = 0;
    $porIncidencia = [];
    $cierrePorTecnico = []; // tecnico => [horas, ...]

    foreach ($filas as $fila) {
        $id = (string)$fila['id'];
        $fechaCreacion = $fila['fecha'] ?? null;
        if (empty($fechaCreacion)) continue;
        $tsCreacion = strtotime($fechaCreacion);
        if ($tsCreacion === false) continue;

        $tsRespuesta = null;
        $tsCierre = null;
        $huboCierre = false;
        $seReabrio = false;

        foreach (($eventosPorId[$id] ?? []) as $ev) {
            $datos = json_decode($ev['datos_nuevos'] ?? '', true);
            if (!is_array($datos) || !array_key_exists('estatus', $datos)) continue;

            $estatusLower = strtolower(trim((string)$datos['estatus']));
            $tsEvento = strtotime($ev['creado_en']);
            if ($tsEvento === false) continue;

            if ($tsRespuesta === null && !in_array($estatusLower, ESTADOS_SIN_RESPUESTA, true)) {
                $tsRespuesta = $tsEvento;
            }

            if (in_array($estatusLower, ESTADOS_CERRADOS, true)) {
                if ($tsCierre === null) $tsCierre = $tsEvento;
                $huboCierre = true;
            } elseif ($huboCierre && in_array($estatusLower, ESTADOS_SIN_RESPUESTA, true)) {
                $seReabrio = true;
            }
        }

        $respuestaHoras = ($tsRespuesta !== null && $tsRespuesta >= $tsCreacion) ? ($tsRespuesta - $tsCreacion) / 3600 : null;
        $cierreHoras = ($tsCierre !== null && $tsCierre >= $tsCreacion) ? ($tsCierre - $tsCreacion) / 3600 : null;

        if ($respuestaHoras !== null) $tiemposRespuesta[] = $respuestaHoras;
        if ($cierreHoras !== null) {
            $tiemposCierre[] = $cierreHoras;
            foreach (separarTecnicos($fila['tecnico'] ?? '') as $tecnico) {
                $cierrePorTecnico[$tecnico][] = $cierreHoras;
            }
        }
        if ($seReabrio) $reabiertas++;

        $porIncidencia[$id] = [
            'respuesta_horas' => $respuestaHoras,
            'cierre_horas' => $cierreHoras,
            'reabierta' => $seReabrio,
        ];
    }

    $buckets = [
        ['label' => '< 1 día', 'cantidad' => 0],
        ['label' => '1-3 días', 'cantidad' => 0],
        ['label' => '3-7 días', 'cantidad' => 0],
        ['label' => '> 7 días', 'cantidad' => 0],
    ];
    foreach ($tiemposCierre as $horas) {
        $dias = $horas / 24;
        if ($dias < 1) $buckets[0]['cantidad']++;
        elseif ($dias < 3) $buckets[1]['cantidad']++;
        elseif ($dias < 7) $buckets[2]['cantidad']++;
        else $buckets[3]['cantidad']++;
    }

    $dentroSlaRespuesta = array_filter($tiemposRespuesta, fn($h) => $h <= SLA_RESPUESTA_HORAS);
    $dentroSlaCierre = array_filter($tiemposCierre, fn($h) => $h <= SLA_CIERRE_HORAS);

    $porTecnico = [];
    foreach ($cierrePorTecnico as $tecnico => $horas) {
        $porTecnico[$tecnico] = [
            'muestras' => count($horas),
            'cierre_mediana_horas' => mediana($horas),
            'cierre_promedio_horas' => promedio($horas),
        ];
    }

    return [
        'por_incidencia' => $porIncidencia,
        'agregado' => [
            'respuesta_promedio_horas' => promedio($tiemposRespuesta),
            'respuesta_mediana_horas' => mediana($tiemposRespuesta),
            'muestras_respuesta' => count($tiemposRespuesta),
            'cierre_promedio_horas' => promedio($tiemposCierre),
            'cierre_mediana_horas' => mediana($tiemposCierre),
            'muestras_cierre' => count($tiemposCierre),
            'sla_respuesta_pct' => count($tiemposRespuesta) > 0 ? round((count($dentroSlaRespuesta) / count($tiemposRespuesta)) * 100, 1) : null,
            'sla_cierre_pct' => count($tiemposCierre) > 0 ? round((count($dentroSlaCierre) / count($tiemposCierre)) * 100, 1) : null,
            'reabiertas' => $reabiertas,
            'total_incidencias' => count($filas),
            'cierre_buckets' => $buckets,
        ],
        'por_tecnico' => $porTecnico,
    ];
}

/**
 * Cuenta y agrega incidencias por técnico individual (separando el campo
 * "tecnico" por "/") en una sola consulta, en vez de una consulta por
 * técnico como antes.
 */
function calcularEstadisticasTecnicos($conn, $filtros_where) {
    $conector = empty($filtros_where) ? "WHERE" : "AND";
    $sql = "SELECT tecnico, estatus FROM incidencias i {$filtros_where} {$conector} tecnico IS NOT NULL AND tecnico != ''";
    $filas = ejecutarConsulta($conn, $sql);

    $stats = [];
    foreach ($filas as $fila) {
        $estatusLower = strtolower(trim($fila['estatus'] ?? ''));
        $completada = in_array($estatusLower, ESTADOS_CERRADOS, true);

        foreach (separarTecnicos($fila['tecnico']) as $tecnico) {
            if (!isset($stats[$tecnico])) {
                $stats[$tecnico] = ['asignadas' => 0, 'completadas' => 0];
            }
            $stats[$tecnico]['asignadas']++;
            if ($completada) $stats[$tecnico]['completadas']++;
        }
    }

    $resultado = [];
    foreach ($stats as $tecnico => $s) {
        $eficiencia = $s['asignadas'] > 0 ? round(($s['completadas'] / $s['asignadas']) * 100, 1) : 0;
        $resultado[$tecnico] = [
            'asignadas' => $s['asignadas'],
            'completadas' => $s['completadas'],
            'eficiencia' => $eficiencia,
            'pendientes' => $s['asignadas'] - $s['completadas'],
        ];
    }

    // Ordenar por asignadas descendente (igual que el comportamiento previo)
    uasort($resultado, fn($a, $b) => $b['asignadas'] - $a['asignadas']);

    return $resultado;
}

/**
 * Envía un CSV con el detalle de las incidencias filtradas, incluyendo
 * tiempos reales de respuesta/cierre calculados desde auditoria, y termina
 * la ejecución.
 */
function exportarCsv($conn, $pdo, $filtros_where) {
    $sql = "SELECT id, numero_incidente, cliente, sucursal, tecnico, equipo, falla, estatus, fecha
            FROM incidencias i {$filtros_where}
            ORDER BY fecha DESC";
    $filas = ejecutarConsulta($conn, $sql);

    $analisis = analizarTiemposIncidencias($pdo, $filas);
    $porIncidencia = $analisis['por_incidencia'];

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="estadisticas_incidencias_' . date('Y-m-d_His') . '.csv"');

    $salida = fopen('php://output', 'w');
    fwrite($salida, "\xEF\xBB\xBF"); // BOM para que Excel abra los acentos bien
    fputcsv($salida, ['Folio', 'Cliente', 'Sucursal', 'Técnico', 'Equipo', 'Falla', 'Estatus', 'Fecha', 'Tiempo de respuesta (h)', 'Tiempo de cierre (días)', 'Reabierta']);

    foreach ($filas as $fila) {
        $tiempos = $porIncidencia[(string)$fila['id']] ?? ['respuesta_horas' => null, 'cierre_horas' => null, 'reabierta' => false];
        fputcsv($salida, [
            $fila['numero_incidente'],
            $fila['cliente'],
            $fila['sucursal'],
            $fila['tecnico'],
            $fila['equipo'],
            $fila['falla'],
            $fila['estatus'],
            $fila['fecha'],
            $tiempos['respuesta_horas'] !== null ? round($tiempos['respuesta_horas'], 1) : '',
            $tiempos['cierre_horas'] !== null ? round($tiempos['cierre_horas'] / 24, 1) : '',
            $tiempos['reabierta'] ? 'Sí' : 'No',
        ]);
    }

    fclose($salida);
    exit();
}

// --- 3. LÓGICA DE ACCIONES ---

$action = $_GET['action'] ?? '';
$response = ['success' => false, 'data' => [], 'error' => 'Acción no válida.'];

$rango_actual = construirFiltros($conn, 'i', 'fecha');
$filtros_where = $rango_actual['where'];

if ($action === 'exportar_csv') {
    exportarCsv($conn, $pdo, $filtros_where);
}

switch ($action) {

    case 'filtros_opciones':
        $sql_sucursales = "SELECT DISTINCT sucursal FROM {$tabla_incidencias} WHERE sucursal IS NOT NULL AND sucursal <> '' ORDER BY sucursal";
        $sucursales = array_column(ejecutarConsulta($conn, $sql_sucursales), 'sucursal');

        $sql_tecnicos_raw = "SELECT DISTINCT tecnico FROM {$tabla_incidencias} WHERE tecnico IS NOT NULL AND tecnico <> ''";
        $filas_tecnicos = ejecutarConsulta($conn, $sql_tecnicos_raw);
        $tecnicos = [];
        foreach ($filas_tecnicos as $fila) {
            foreach (separarTecnicos($fila['tecnico']) as $t) {
                if (!in_array($t, $tecnicos, true)) $tecnicos[] = $t;
            }
        }
        sort($tecnicos, SORT_STRING | SORT_FLAG_CASE);

        $response['success'] = true;
        $response['data'] = ['tecnicos' => $tecnicos, 'sucursales' => $sucursales];
        break;

    case 'incidencias_por_estatus':
        // Detalle ("drill-down") para poder revisar y corregir de inmediato
        // las incidencias detrás de una porción del gráfico de estatus o de
        // una tarjeta de KPI (p.ej. si un folio quedó con el estatus mal
        // capturado). Respeta el rango de fechas/técnico/sucursal activos,
        // pero ignora el filtro global de estatus: el estatus a mostrar lo
        // define el elemento en el que se hizo clic.
        $modo = $_GET['modo'] ?? 'estatus';

        if ($modo === 'reabiertas') {
            $sql_dataset = "SELECT id, fecha, estatus, tecnico FROM {$tabla_incidencias} i {$filtros_where}";
            $dataset = ejecutarConsulta($conn, $sql_dataset);
            $porIncidencia = analizarTiemposIncidencias($pdo, $dataset)['por_incidencia'];
            $idsReabiertas = array_keys(array_filter($porIncidencia, fn($x) => $x['reabierta']));

            if (empty($idsReabiertas)) {
                $response['success'] = true;
                $response['data'] = ['incidencias' => [], 'total' => 0, 'limitado' => false];
                break;
            }

            $placeholders = implode(',', array_fill(0, count($idsReabiertas), '?'));
            $stmt = $pdo->prepare("
                SELECT id, numero_incidente, cliente, sucursal, tecnico, equipo, falla, estatus, fecha
                FROM {$tabla_incidencias}
                WHERE id IN ($placeholders)
                ORDER BY fecha DESC
            ");
            $stmt->execute($idsReabiertas);
            $filas = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $response['success'] = true;
            $response['data'] = ['incidencias' => $filas, 'total' => count($filas), 'limitado' => false];
            break;
        }

        $estatusClic = trim($_GET['estatusClic'] ?? '');
        if ($estatusClic === '') {
            $response['error'] = 'Falta indicar el estatus a consultar.';
            break;
        }

        $estados = array_values(array_filter(array_map('trim', explode(',', $estatusClic)), fn($e) => $e !== ''));
        if (empty($estados)) {
            $response['error'] = 'Estatus no válido.';
            break;
        }

        $condicionesEstatus = array_map(
            fn($e) => "LOWER(estatus) = LOWER('" . $conn->real_escape_string($e) . "')",
            $estados
        );
        $conector = empty($filtros_where) ? "WHERE" : "AND";
        $sqlWhereEstatus = "(" . implode(" OR ", $condicionesEstatus) . ")";

        $sql = "SELECT id, numero_incidente, cliente, sucursal, tecnico, equipo, falla, estatus, fecha
                FROM {$tabla_incidencias} i {$filtros_where} {$conector} {$sqlWhereEstatus}
                ORDER BY fecha DESC
                LIMIT 300";
        $filas = ejecutarConsulta($conn, $sql);

        $response['success'] = true;
        $response['data'] = ['incidencias' => $filas, 'total' => count($filas), 'limitado' => count($filas) >= 300];
        break;

    case 'estadisticas_generales':

        // 1. Total de incidencias (CON filtros)
        $sql_total = "SELECT COUNT(id) AS total_incidencias FROM {$tabla_incidencias} i {$filtros_where}";
        $total_incidencias = ejecutarConsulta($conn, $sql_total)[0]['total_incidencias'] ?? 0;

        // 2. Incidencias por estatus (CON filtros, comparación case-insensitive)
        $sql_abiertas = "SELECT COUNT(id) AS count FROM {$tabla_incidencias} i {$filtros_where} AND LOWER(estatus) = 'abierto'";
        $abiertas = ejecutarConsulta($conn, $sql_abiertas)[0]['count'] ?? 0;

        $sql_pendientes = "SELECT COUNT(id) AS count FROM {$tabla_incidencias} i {$filtros_where} AND LOWER(estatus) = 'pendiente'";
        $pendientes = ejecutarConsulta($conn, $sql_pendientes)[0]['count'] ?? 0;

        $sql_asignadas = "SELECT COUNT(id) AS count FROM {$tabla_incidencias} i {$filtros_where} AND LOWER(estatus) = 'asignado'";
        $asignadas = ejecutarConsulta($conn, $sql_asignadas)[0]['count'] ?? 0;

        $sql_completadas = "SELECT COUNT(id) AS count FROM {$tabla_incidencias} i {$filtros_where} AND LOWER(estatus) = 'completado'";
        $completadas = ejecutarConsulta($conn, $sql_completadas)[0]['count'] ?? 0;

        $sql_cerradas_factura = "SELECT COUNT(id) AS count FROM {$tabla_incidencias} i {$filtros_where} AND LOWER(estatus) = 'cerrado con factura'";
        $cerradas_factura = ejecutarConsulta($conn, $sql_cerradas_factura)[0]['count'] ?? 0;

        $sql_cerradas_sin_factura = "SELECT COUNT(id) AS count FROM {$tabla_incidencias} i {$filtros_where} AND LOWER(estatus) = 'cerrado sin factura'";
        $cerradas_sin_factura = ejecutarConsulta($conn, $sql_cerradas_sin_factura)[0]['count'] ?? 0;

        // 3. Total de resueltas (CON filtros)
        $resueltas_totales = $completadas + $cerradas_factura + $cerradas_sin_factura;

        // 4. Total de clientes únicos - SIN FILTROS (TOTAL GENERAL)
        $sql_clientes = "SELECT COUNT(DISTINCT cliente) AS total_clientes FROM {$tabla_incidencias} WHERE cliente IS NOT NULL AND cliente <> ''";
        $total_clientes = ejecutarConsulta($conn, $sql_clientes)[0]['total_clientes'] ?? 0;

        // 5. Eficiencia (resueltas vs total CON filtros)
        $eficiencia_total = $total_incidencias > 0 ? round(($resueltas_totales / $total_incidencias) * 100, 1) : 0;

        // 6. Estadísticas de equipos (CON filtros)
        $sql_equipos = "SELECT COUNT(DISTINCT equipo) AS total_equipos FROM {$tabla_incidencias} i {$filtros_where} AND equipo IS NOT NULL AND equipo <> ''";
        $total_equipos = ejecutarConsulta($conn, $sql_equipos)[0]['total_equipos'] ?? 0;

        // 7. Top tipos de falla (CON filtros)
        $conector = empty($filtros_where) ? "WHERE" : "AND";
        $sql_fallas = "SELECT falla, COUNT(*) as cantidad FROM {$tabla_incidencias} i {$filtros_where} {$conector} falla IS NOT NULL AND falla != '' GROUP BY falla ORDER BY cantidad DESC LIMIT 5";
        $top_fallas = ejecutarConsulta($conn, $sql_fallas);

        // 8. Tiempos reales de respuesta y cierre, calculados desde auditoria
        $sql_dataset = "SELECT id, fecha, estatus, tecnico FROM {$tabla_incidencias} i {$filtros_where}";
        $dataset = ejecutarConsulta($conn, $sql_dataset);
        $analisis_tiempos = analizarTiemposIncidencias($pdo, $dataset)['agregado'];

        // 9. Tendencia real vs el período anterior equivalente (misma duración, inmediatamente antes)
        $dtIni = new DateTime($rango_actual['fecha_inicio']);
        $dtFin = new DateTime($rango_actual['fecha_fin']);
        $dias_periodo = (int)$dtIni->diff($dtFin)->format('%a') + 1;
        $prevFin = (clone $dtIni)->modify('-1 day')->format('Y-m-d');
        $prevIni = (clone $dtIni)->modify('-' . $dias_periodo . ' days')->format('Y-m-d');
        $rango_anterior = construirFiltros($conn, 'i', 'fecha', $prevIni, $prevFin);
        $sql_total_anterior = "SELECT COUNT(id) AS total FROM {$tabla_incidencias} i {$rango_anterior['where']}";
        $total_anterior = ejecutarConsulta($conn, $sql_total_anterior)[0]['total'] ?? 0;
        $tendencia_incidencias = $total_anterior > 0
            ? round((($total_incidencias - $total_anterior) / $total_anterior) * 100, 1)
            : ($total_incidencias > 0 ? 100 : 0);

        $response['success'] = true;
        $response['data'] = [
            'total_incidencias' => (int)$total_incidencias,
            'incidencias_abiertas' => (int)$abiertas,
            'incidencias_pendientes' => (int)$pendientes,
            'incidencias_asignadas' => (int)$asignadas,
            'incidencias_completadas' => (int)$completadas,
            'incidencias_cerradas_factura' => (int)$cerradas_factura,
            'incidencias_cerradas_sin_factura' => (int)$cerradas_sin_factura,
            'incidencias_resueltas' => (int)$resueltas_totales,
            'total_clientes' => (int)$total_clientes,
            'total_equipos' => (int)$total_equipos,
            'eficiencia_total' => $eficiencia_total,
            'top_fallas' => $top_fallas,
            'tendencia_incidencias' => $tendencia_incidencias,
            'tiempos' => $analisis_tiempos,
            'last_updated' => date('H:i:s'),
        ];
        break;

    case 'estadisticas_incidencias':
        $data_incidencias = [];

        // Gráfico 1: Por Estatus
        $sql_estatus = "SELECT estatus, COUNT(id) AS cantidad FROM {$tabla_incidencias} i {$filtros_where} GROUP BY estatus ORDER BY cantidad DESC";
        $data_incidencias['por_estatus'] = ejecutarConsulta($conn, $sql_estatus);

        // Gráfico 2: Por Sucursal
        $sql_sucursal = "SELECT sucursal, COUNT(id) AS cantidad FROM {$tabla_incidencias} i {$filtros_where} GROUP BY sucursal ORDER BY cantidad DESC";
        $data_incidencias['por_sucursal'] = ejecutarConsulta($conn, $sql_sucursal);

        // Gráfico 3: Histórico mensual
        $sql_mensual = "SELECT DATE_FORMAT(fecha, '%Y-%m') as mes, COUNT(id) AS cantidad FROM {$tabla_incidencias} i {$filtros_where} GROUP BY mes ORDER BY mes ASC";
        $data_incidencias['mensuales'] = ejecutarConsulta($conn, $sql_mensual);

        // Gráfico 4: Top 10 Clientes con más incidencias
        $conector = empty($filtros_where) ? "WHERE" : "AND";
        $sql_clientes = "SELECT cliente, COUNT(id) AS cantidad FROM {$tabla_incidencias} i {$filtros_where} {$conector} cliente IS NOT NULL AND cliente != '' GROUP BY cliente ORDER BY cantidad DESC LIMIT 10";
        $data_incidencias['top_clientes'] = ejecutarConsulta($conn, $sql_clientes);

        // Gráfico 5: Por Técnico (una sola consulta, sin N+1)
        $tecnicos_stats = calcularEstadisticasTecnicos($conn, $filtros_where);
        $data_incidencias['por_tecnico'] = array_map(
            fn($tecnico, $s) => ['tecnico' => $tecnico, 'cantidad' => $s['asignadas']],
            array_keys($tecnicos_stats),
            $tecnicos_stats
        );

        // Gráfico 6: Top tipos de equipo
        $conector = empty($filtros_where) ? "WHERE" : "AND";
        $sql_equipos = "SELECT equipo, COUNT(id) AS cantidad FROM {$tabla_incidencias} i {$filtros_where} {$conector} equipo IS NOT NULL AND equipo != '' GROUP BY equipo ORDER BY cantidad DESC LIMIT 8";
        $data_incidencias['por_equipo'] = ejecutarConsulta($conn, $sql_equipos);

        // Gráfico 7: Distribución real de tiempos de cierre (reemplaza el chart de "prioridad" que no existía en BD)
        $sql_dataset = "SELECT id, fecha, estatus, tecnico FROM {$tabla_incidencias} i {$filtros_where}";
        $dataset = ejecutarConsulta($conn, $sql_dataset);
        $data_incidencias['cierre_buckets'] = analizarTiemposIncidencias($pdo, $dataset)['agregado']['cierre_buckets'];

        $response['success'] = true;
        $response['data'] = $data_incidencias;
        break;

    case 'estadisticas_tecnicos':
        $estadisticas_tecnicos = calcularEstadisticasTecnicos($conn, $filtros_where);

        $sql_dataset = "SELECT id, fecha, estatus, tecnico FROM {$tabla_incidencias} i {$filtros_where}";
        $dataset = ejecutarConsulta($conn, $sql_dataset);
        $tiempos_por_tecnico = analizarTiemposIncidencias($pdo, $dataset)['por_tecnico'];

        $tecnico_eficiente = '';
        $tecnico_mas_asignadas = '';
        $tecnico_mas_completadas = '';
        $tecnico_mas_rapido = '';
        $max_eficiencia = 0;
        $max_asignadas = 0;
        $max_completadas = 0;
        $menor_mediana_cierre = null;

        foreach ($estadisticas_tecnicos as $tecnico => $stats) {
            if ($stats['eficiencia'] > $max_eficiencia && $stats['asignadas'] >= 3) {
                $max_eficiencia = $stats['eficiencia'];
                $tecnico_eficiente = $tecnico;
            }
            if ($stats['asignadas'] > $max_asignadas) {
                $max_asignadas = $stats['asignadas'];
                $tecnico_mas_asignadas = $tecnico;
            }
            if ($stats['completadas'] > $max_completadas) {
                $max_completadas = $stats['completadas'];
                $tecnico_mas_completadas = $tecnico;
            }

            $tiempo = $tiempos_por_tecnico[$tecnico] ?? null;
            if ($tiempo && $tiempo['muestras'] >= 3) {
                if ($menor_mediana_cierre === null || $tiempo['cierre_mediana_horas'] < $menor_mediana_cierre) {
                    $menor_mediana_cierre = $tiempo['cierre_mediana_horas'];
                    $tecnico_mas_rapido = $tecnico;
                }
            }
        }

        $labels_rendimiento = [];
        $datos_asignadas = [];
        $datos_completadas = [];
        $labels_eficiencia = [];
        $datos_eficiencia = [];
        $labels_tiempos = [];
        $datos_tiempos_dias = [];

        foreach ($estadisticas_tecnicos as $tecnico => $stats) {
            if ($stats['asignadas'] > 0) {
                $labels_rendimiento[] = $tecnico;
                $datos_asignadas[] = $stats['asignadas'];
                $datos_completadas[] = $stats['completadas'];
                $labels_eficiencia[] = $tecnico;
                $datos_eficiencia[] = $stats['eficiencia'];

                $tiempo = $tiempos_por_tecnico[$tecnico] ?? null;
                if ($tiempo && $tiempo['muestras'] > 0) {
                    $labels_tiempos[] = $tecnico;
                    $datos_tiempos_dias[] = round($tiempo['cierre_mediana_horas'] / 24, 1);
                }
            }
        }

        $response['success'] = true;
        $response['data'] = [
            'tecnico_eficiente' => $tecnico_eficiente ?: 'N/A',
            'tecnico_mas_asignadas' => $tecnico_mas_asignadas ?: 'N/A',
            'tecnico_mas_completadas' => $tecnico_mas_completadas ?: 'N/A',
            'tecnico_mas_rapido' => $tecnico_mas_rapido ?: 'N/A',
            'total_tecnicos' => count($estadisticas_tecnicos),
            'estadisticas' => $estadisticas_tecnicos,
            'graficos' => [
                'rendimiento' => [
                    'labels' => $labels_rendimiento,
                    'datos_asignadas' => $datos_asignadas,
                    'datos_completadas' => $datos_completadas,
                ],
                'eficiencia' => [
                    'labels' => $labels_eficiencia,
                    'datos' => $datos_eficiencia,
                ],
                'tiempos_cierre' => [
                    'labels' => $labels_tiempos,
                    'datos_dias' => $datos_tiempos_dias,
                ],
            ],
        ];
        break;

    default:
        $response['error'] = 'Acción de API no reconocida.';
        break;
}

$conn->close();
echo json_encode($response);
