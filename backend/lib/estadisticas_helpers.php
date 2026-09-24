<?php
// Lógica compartida entre el panel general de estadísticas
// (backend/estadisticas.php) y el dashboard personal del técnico
// (backend/estadisticas_tecnico.php), para no duplicar el cálculo de
// tiempos reales de respuesta/cierre en dos archivos.

// Umbrales de SLA (mismo criterio de 7 días que ya usa backend/alertas_incidentes.php)
const SLA_RESPUESTA_HORAS = 4;
const SLA_CIERRE_HORAS = 24 * 7;

// Estatus considerados "cierre" (coincide con la definición de "resueltas" ya usada en el panel)
const ESTADOS_CERRADOS = ['completado', 'cerrado con factura', 'cerrado sin factura', 'resuelto'];
// Estatus considerados "sin tocar todavía" (para medir primera respuesta)
const ESTADOS_SIN_RESPUESTA = ['abierto', ''];

/**
 * Recopila y sanea los parámetros de filtro de la URL.
 * Si se pasan $fechaInicioOverride/$fechaFinOverride, se usan esas fechas
 * en vez de leer rangoFecha/fechaInicio/fechaFin de la URL (para poder
 * calcular el período anterior equivalente y sacar una tendencia real).
 * Devuelve el WHERE listo para usar y las fechas efectivas que se aplicaron.
 *
 * $tecnicoForzado: si se pasa, ignora el parámetro GET "tecnico" y filtra
 * exactamente por ese valor (usado por el dashboard del técnico para que
 * SIEMPRE filtre por su propia identidad de sesión, nunca por lo que
 * venga en la URL).
 */
function construirFiltros($conn, $tabla_alias = 'i', $campo_fecha = 'fecha', $fechaInicioOverride = null, $fechaFinOverride = null, $tecnicoForzado = null) {
    $filtros = [];

    $tecnico = $tecnicoForzado !== null ? $tecnicoForzado : ($_GET['tecnico'] ?? '');
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
 * true si $nombreSesion coincide (sin distinguir mayúsculas/acentos de caja)
 * con alguno de los técnicos individuales listados en el campo "tecnico" de
 * una incidencia. Es la misma comparación exacta-tras-separar que ya usa
 * calcularEstadisticasTecnicos, reutilizada aquí para decidir "esto es mío"
 * en el dashboard del técnico (a propósito NO es un LIKE amplio: con acceso
 * a datos de una sola persona, una coincidencia parcial falsa sería grave).
 */
function tecnicoCoincide($nombreSesion, $valorTecnico) {
    $nombreSesion = trim($nombreSesion);
    if ($nombreSesion === '') return false;
    foreach (separarTecnicos($valorTecnico) as $t) {
        if (mb_strtolower($t) === mb_strtolower($nombreSesion)) {
            return true;
        }
    }
    return false;
}

/**
 * Calcula, a partir del historial real en `auditoria`, el tiempo de primera
 * respuesta y el tiempo de cierre de cada incidencia del conjunto filtrado,
 * en una sola consulta (nada de N+1). También agrega métricas globales,
 * por técnico y por sucursal.
 *
 * $filas: cada elemento debe traer al menos id, fecha, estatus y (opcional) tecnico/sucursal.
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
        'por_sucursal' => [],
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
    $reabiertasPorTecnico = []; // tecnico => cantidad
    $cierrePorSucursal = []; // sucursal => [horas, ...]

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
                if ($seReabrio) {
                    $reabiertasPorTecnico[$tecnico] = ($reabiertasPorTecnico[$tecnico] ?? 0) + 1;
                }
            }
            $sucursal = trim($fila['sucursal'] ?? '');
            if ($sucursal !== '') {
                $cierrePorSucursal[$sucursal][] = $cierreHoras;
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
        $dentroSla = array_filter($horas, fn($h) => $h <= SLA_CIERRE_HORAS);
        $porTecnico[$tecnico] = [
            'muestras' => count($horas),
            'cierre_mediana_horas' => mediana($horas),
            'cierre_promedio_horas' => promedio($horas),
            'sla_pct' => round((count($dentroSla) / count($horas)) * 100, 1),
            'reabiertas' => $reabiertasPorTecnico[$tecnico] ?? 0,
        ];
    }

    $porSucursal = [];
    foreach ($cierrePorSucursal as $sucursal => $horas) {
        $dentroSla = array_filter($horas, fn($h) => $h <= SLA_CIERRE_HORAS);
        $porSucursal[$sucursal] = [
            'muestras' => count($horas),
            'cierre_mediana_horas' => mediana($horas),
            'cierre_promedio_horas' => promedio($horas),
            'sla_pct' => round((count($dentroSla) / count($horas)) * 100, 1),
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
        'por_sucursal' => $porSucursal,
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
