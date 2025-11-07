<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// --- 1. CONEXIÓN DE BASE DE DATOS ---
require_once 'conexion.php'; 

// Verifica la conexión
if (!isset($conn) || $conn->connect_error) {
    echo json_encode([
        'success' => false, 
        'error' => 'Error al cargar la conexión a la base de datos. Por favor, revisa conexion.php.'
    ]);
    exit();
}

// Nombre de la tabla de incidencias
$tabla_incidencias = "incidencias"; 

// --- 2. FUNCIONES DE UTILIDAD ---

/**
 * Recopila y sanea los parámetros de filtro de la URL.
 */
function construirFiltros($conn, $tabla_alias = 'i', $campo_fecha = 'fecha') {
    $filtros = [];
    
    // Usar los mismos nombres que el frontend
    $rango = $_GET['rangoFecha'] ?? '30';
    $tecnico = $_GET['tecnico'] ?? '';
    $sucursal = $_GET['sucursal'] ?? '';
    $estatus = $_GET['estatus'] ?? '';
    
    $campo_fecha_db = $tabla_alias . "." . $campo_fecha;
    
    $fecha_actual = new DateTime();
    $fecha_inicio = null;
    $fecha_fin = $fecha_actual->format('Y-m-d'); 
    
    // Lógica de rango de fechas
    if ($rango === 'custom' && !empty($_GET['fechaInicio']) && !empty($_GET['fechaFin'])) {
        $fecha_inicio = $_GET['fechaInicio'];
        $fecha_fin = $_GET['fechaFin'];
    } else {
        $dias = intval($rango);
        if ($dias > 0) {
            $fecha_inicio = (clone $fecha_actual)->modify("-$dias days")->format('Y-m-d');
        } else {
            // Por defecto últimos 30 días
            $fecha_inicio = (clone $fecha_actual)->modify('-30 days')->format('Y-m-d');
        }
    }
    
    if ($fecha_inicio && $fecha_fin) {
        $filtros[] = "{$campo_fecha_db} BETWEEN '{$conn->real_escape_string($fecha_inicio)} 00:00:00' AND '{$conn->real_escape_string($fecha_fin)} 23:59:59'";
    }

    // Filtros por selección
    if (!empty($tecnico)) {
        $valor = $conn->real_escape_string($tecnico);
        $filtros[] = "{$tabla_alias}.tecnico LIKE '%{$valor}%'";
    }

    if (!empty($sucursal)) {
        $valor = $conn->real_escape_string($sucursal);
        $filtros[] = "{$tabla_alias}.sucursal = '{$valor}'";
    }

    if (!empty($estatus)) {
        $valor = $conn->real_escape_string($estatus);
        $filtros[] = "{$tabla_alias}.estatus = '{$valor}'";
    }

    return empty($filtros) ? "" : "WHERE " . implode(" AND ", $filtros);
}

/**
 * Extrae técnicos individuales del campo tecnico (separados por diagonal "/")
 */
function extraerTecnicosIndividuales($conn, $filtros_where) {
    $sql = "
        SELECT 
            tecnico
        FROM 
            incidencias i
        {$filtros_where}
        AND tecnico IS NOT NULL 
        AND tecnico != ''
    ";
    
    $resultados = ejecutarConsulta($conn, $sql);
    $tecnicos_individuales = [];
    
    foreach ($resultados as $fila) {
        // Separar por diagonal "/" y limpiar cada técnico
        $tecnicos = explode('/', trim($fila['tecnico']));
        
        foreach ($tecnicos as $tecnico) {
            $tecnico_limpio = trim($tecnico);
            
            // Filtrar solo nombres válidos (más de 2 caracteres, no vacíos)
            if (strlen($tecnico_limpio) > 2 && 
                !empty($tecnico_limpio) &&
                $tecnico_limpio !== '/' &&
                !in_array($tecnico_limpio, $tecnicos_individuales)) {
                
                $tecnicos_individuales[] = $tecnico_limpio;
            }
        }
    }
    
    return array_unique($tecnicos_individuales);
}

/**
 * Cuenta incidencias por técnico individual con separación por diagonal "/"
 */
function contarIncidenciasPorTecnico($conn, $filtros_where) {
    $tecnicos_unicos = extraerTecnicosIndividuales($conn, $filtros_where);
    $tecnicos_data = [];
    
    foreach ($tecnicos_unicos as $tecnico) {
        $tecnico_escape = $conn->real_escape_string($tecnico);
        
        // Buscar incidencias donde el técnico aparece (búsqueda exacta considerando diagonales)
        $sql_count = "
            SELECT COUNT(*) as cantidad
            FROM incidencias i
            {$filtros_where}
            AND (
                i.tecnico = '{$tecnico_escape}' 
                OR i.tecnico LIKE '{$tecnico_escape}/%'
                OR i.tecnico LIKE '%/{$tecnico_escape}'
                OR i.tecnico LIKE '%/{$tecnico_escape}/%'
                OR i.tecnico LIKE '{$tecnico_escape} %'
                OR i.tecnico LIKE '% {$tecnico_escape}'
                OR i.tecnico LIKE '% {$tecnico_escape} %'
            )
        ";
        
        $cantidad = ejecutarConsulta($conn, $sql_count)[0]['cantidad'] ?? 0;
        
        if ($cantidad > 0) {
            $tecnicos_data[] = [
                'tecnico' => $tecnico,
                'cantidad' => $cantidad
            ];
        }
    }
    
    // Ordenar por cantidad descendente
    usort($tecnicos_data, function($a, $b) {
        return $b['cantidad'] - $a['cantidad'];
    });
    
    return $tecnicos_data;
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
        while($fila = $resultado->fetch_assoc()) {
            $data[] = $fila;
        }
    }
    return $data;
}

/**
 * Calcula estadísticas de técnicos basado en incidencias asignadas
 */
function calcularEstadisticasTecnicos($conn, $filtros_where) {
    $estadisticas = [];
    $tecnicos_data = contarIncidenciasPorTecnico($conn, $filtros_where);
    
    foreach ($tecnicos_data as $tecnico_info) {
        $tecnico = $tecnico_info['tecnico'];
        $tecnico_escape = $conn->real_escape_string($tecnico);
        
        // Incidencias asignadas a este técnico
        $sql_asignadas = "
            SELECT COUNT(*) as total
            FROM incidencias i
            {$filtros_where}
            AND (
                i.tecnico = '{$tecnico_escape}' 
                OR i.tecnico LIKE '{$tecnico_escape}/%'
                OR i.tecnico LIKE '%/{$tecnico_escape}'
                OR i.tecnico LIKE '%/{$tecnico_escape}/%'
                OR i.tecnico LIKE '{$tecnico_escape} %'
                OR i.tecnico LIKE '% {$tecnico_escape}'
                OR i.tecnico LIKE '% {$tecnico_escape} %'
            )
        ";
        $asignadas = ejecutarConsulta($conn, $sql_asignadas)[0]['total'] ?? 0;
        
        // Incidencias completadas por este técnico
        $sql_completadas = "
            SELECT COUNT(*) as total
            FROM incidencias i
            {$filtros_where}
            AND (
                i.tecnico = '{$tecnico_escape}' 
                OR i.tecnico LIKE '{$tecnico_escape}/%'
                OR i.tecnico LIKE '%/{$tecnico_escape}'
                OR i.tecnico LIKE '%/{$tecnico_escape}/%'
                OR i.tecnico LIKE '{$tecnico_escape} %'
                OR i.tecnico LIKE '% {$tecnico_escape}'
                OR i.tecnico LIKE '% {$tecnico_escape} %'
            )
            AND i.estatus IN ('completado', 'cerrado con factura', 'cerrado sin factura', 'resuelto')
        ";
        $completadas = ejecutarConsulta($conn, $sql_completadas)[0]['total'] ?? 0;
        
        // Tasa de eficiencia
        $eficiencia = $asignadas > 0 ? round(($completadas / $asignadas) * 100, 1) : 0;
        
        $estadisticas[$tecnico] = [
            'asignadas' => $asignadas,
            'completadas' => $completadas,
            'eficiencia' => $eficiencia,
            'pendientes' => $asignadas - $completadas
        ];
    }
    
    return $estadisticas;
}

// --- 3. LÓGICA DE ACCIONES ---

$action = $_GET['action'] ?? '';
$response = ['success' => false, 'data' => [], 'error' => 'Acción no válida.'];

// Filtro principal para incidencias (EXCEPTO para total de clientes)
$filtros_where = construirFiltros($conn, 'i', 'fecha');

switch ($action) {
    
    case 'estadisticas_generales':
        
        // 1. Total de incidencias (CON filtros)
        $sql_total = "SELECT COUNT(id) AS total_incidencias FROM {$tabla_incidencias} i {$filtros_where}";
        $total_incidencias = ejecutarConsulta($conn, $sql_total)[0]['total_incidencias'] ?? 0;

        // 2. Incidencias por estatus (CON filtros)
        $sql_abiertas = "SELECT COUNT(id) AS count FROM {$tabla_incidencias} i {$filtros_where} AND estatus IN ('abierto', 'pendiente')";
        $abiertas = ejecutarConsulta($conn, $sql_abiertas)[0]['count'] ?? 0;
        
        $sql_asignadas = "SELECT COUNT(id) AS count FROM {$tabla_incidencias} i {$filtros_where} AND estatus = 'asignado'";
        $asignadas = ejecutarConsulta($conn, $sql_asignadas)[0]['count'] ?? 0;
        
        $sql_completadas = "SELECT COUNT(id) AS count FROM {$tabla_incidencias} i {$filtros_where} AND estatus = 'completado'";
        $completadas = ejecutarConsulta($conn, $sql_completadas)[0]['count'] ?? 0;
        
        $sql_cerradas_factura = "SELECT COUNT(id) AS count FROM {$tabla_incidencias} i {$filtros_where} AND estatus = 'cerrado con factura'";
        $cerradas_factura = ejecutarConsulta($conn, $sql_cerradas_factura)[0]['count'] ?? 0;
        
        $sql_cerradas_sin_factura = "SELECT COUNT(id) AS count FROM {$tabla_incidencias} i {$filtros_where} AND estatus = 'cerrado sin factura'";
        $cerradas_sin_factura = ejecutarConsulta($conn, $sql_cerradas_sin_factura)[0]['count'] ?? 0;

        // 3. Total de resueltas (CON filtros)
        $resueltas_totales = $completadas + $cerradas_factura + $cerradas_sin_factura;
        
        // 4. Total de clientes únicos - SIN FILTROS (TOTAL GENERAL)
        $sql_clientes = "SELECT COUNT(DISTINCT cliente) AS total_clientes FROM {$tabla_incidencias} WHERE cliente IS NOT NULL AND cliente <> ''";
        $total_clientes_result = ejecutarConsulta($conn, $sql_clientes);
        $total_clientes = $total_clientes_result[0]['total_clientes'] ?? 0;
        
        // 5. Eficiencia (resueltas vs total CON filtros)
        $eficiencia_total = $total_incidencias > 0 ? round(($resueltas_totales / $total_incidencias) * 100, 1) : 0;
        
        // 6. Estadísticas de equipos (CON filtros)
        $sql_equipos = "SELECT COUNT(DISTINCT equipo) AS total_equipos FROM {$tabla_incidencias} i {$filtros_where} AND equipo IS NOT NULL AND equipo <> ''";
        $total_equipos = ejecutarConsulta($conn, $sql_equipos)[0]['total_equipos'] ?? 0;
        
        // 7. Top tipos de falla (CON filtros)
        $sql_fallas = "SELECT falla, COUNT(*) as cantidad FROM {$tabla_incidencias} i {$filtros_where} WHERE falla IS NOT NULL AND falla != '' GROUP BY falla ORDER BY cantidad DESC LIMIT 5";
        $top_fallas = ejecutarConsulta($conn, $sql_fallas);
        
        $response['success'] = true;
        $response['data'] = [
            'total_incidencias' => (int)$total_incidencias,
            'incidencias_abiertas' => (int)$abiertas,
            'incidencias_asignadas' => (int)$asignadas,
            'incidencias_completadas' => (int)$completadas,
            'incidencias_cerradas_factura' => (int)$cerradas_factura,
            'incidencias_cerradas_sin_factura' => (int)$cerradas_sin_factura,
            'incidencias_resueltas' => (int)$resueltas_totales,
            'total_clientes' => (int)$total_clientes, // SIEMPRE total general
            'total_equipos' => (int)$total_equipos,
            'eficiencia_total' => $eficiencia_total,
            'top_fallas' => $top_fallas,
            'tendencia_incidencias' => 0,
            'last_updated' => date('H:i:s')
        ];
        break;

    case 'estadisticas_incidencias':
        $data_incidencias = [];

        // Gráfico 1: Por Estatus (CON filtros)
        $sql_estatus = "SELECT estatus, COUNT(id) AS cantidad FROM {$tabla_incidencias} i {$filtros_where} GROUP BY estatus ORDER BY cantidad DESC";
        $data_incidencias['por_estatus'] = ejecutarConsulta($conn, $sql_estatus);

        // Gráfico 2: Por Sucursal (CON filtros)
        $sql_sucursal = "SELECT sucursal, COUNT(id) AS cantidad FROM {$tabla_incidencias} i {$filtros_where} GROUP BY sucursal ORDER BY cantidad DESC";
        $data_incidencias['por_sucursal'] = ejecutarConsulta($conn, $sql_sucursal);
        
        // Gráfico 3: Histórico mensual (CON filtros)
        $sql_mensual = "SELECT DATE_FORMAT(fecha, '%Y-%m') as mes, COUNT(id) AS cantidad FROM {$tabla_incidencias} i {$filtros_where} GROUP BY mes ORDER BY mes ASC";
        $data_incidencias['mensuales'] = ejecutarConsulta($conn, $sql_mensual);

        // Gráfico 4: Top 10 Clientes con más incidencias (CON filtros)
        $sql_clientes = "SELECT cliente, COUNT(id) AS cantidad FROM {$tabla_incidencias} i {$filtros_where} WHERE cliente IS NOT NULL AND cliente != '' GROUP BY cliente ORDER BY cantidad DESC LIMIT 10";
        $data_incidencias['top_clientes'] = ejecutarConsulta($conn, $sql_clientes);
        
        // Gráfico 5: Por Técnico - USAR FUNCIÓN CORREGIDA
        $data_incidencias['por_tecnico'] = contarIncidenciasPorTecnico($conn, $filtros_where);
        
        // Gráfico 6: Top tipos de equipo (CON filtros)
        $sql_equipos = "SELECT equipo, COUNT(id) AS cantidad FROM {$tabla_incidencias} i {$filtros_where} WHERE equipo IS NOT NULL AND equipo != '' GROUP BY equipo ORDER BY cantidad DESC LIMIT 8";
        $data_incidencias['por_equipo'] = ejecutarConsulta($conn, $sql_equipos);
        
        $response['success'] = true;
        $response['data'] = $data_incidencias;
        break;

    case 'estadisticas_tecnicos':
        // Calcular estadísticas de técnicos (CON filtros)
        $estadisticas_tecnicos = calcularEstadisticasTecnicos($conn, $filtros_where);
        
        // Encontrar técnicos destacados
        $tecnico_eficiente = '';
        $tecnico_mas_asignadas = '';
        $tecnico_mas_completadas = '';
        $max_eficiencia = 0;
        $max_asignadas = 0;
        $max_completadas = 0;
        
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
        }
        
        // Preparar datos para gráficos
        $labels_rendimiento = [];
        $datos_asignadas = [];
        $datos_completadas = [];
        $labels_eficiencia = [];
        $datos_eficiencia = [];
        
        foreach ($estadisticas_tecnicos as $tecnico => $stats) {
            if ($stats['asignadas'] > 0) {
                $labels_rendimiento[] = $tecnico;
                $datos_asignadas[] = $stats['asignadas'];
                $datos_completadas[] = $stats['completadas'];
                $labels_eficiencia[] = $tecnico;
                $datos_eficiencia[] = $stats['eficiencia'];
            }
        }
        
        $response['success'] = true;
        $response['data'] = [
            'tecnico_eficiente' => $tecnico_eficiente ?: 'N/A',
            'tecnico_mas_asignadas' => $tecnico_mas_asignadas ?: 'N/A',
            'tecnico_mas_completadas' => $tecnico_mas_completadas ?: 'N/A',
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
                    'datos' => $datos_eficiencia
                ]
            ]
        ];
        break;

    default:
        $response['error'] = 'Acción de API no reconocida.';
        break;
}

$conn->close();
echo json_encode($response);
?>