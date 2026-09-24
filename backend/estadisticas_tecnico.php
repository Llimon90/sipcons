<?php
// Dashboard personal del técnico: cada quien ve únicamente sus propios
// números. A propósito la identidad NUNCA se toma de un parámetro de la
// URL (a diferencia de backend/estadisticas.php, donde el filtro de
// técnico es una elección del usuario que consulta el panel general) —
// aquí siempre se deriva de la sesión, para que un técnico no pueda ver
// los datos de otro cambiando un query param.
require_once __DIR__ . '/../auth/middleware.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if (!isset($conn) || $conn->connect_error) {
    echo json_encode(['success' => false, 'error' => 'Error al cargar la conexión a la base de datos.']);
    exit();
}

require_once __DIR__ . '/lib/estadisticas_helpers.php';

// --- 1. IDENTIDAD DEL TÉCNICO EN SESIÓN ---
//
// incidencias.tecnico es texto libre (viene de un <select> histórico, no
// de una relación con `usuarios`). En el caso normal, usuarios.nombre
// coincide exactamente con lo que aparece ahí. `alias_tecnico` es una
// válvula de escape: si para alguien no coincide, un admin llena ese
// campo con el texto exacto y se usa en su lugar (ver migración 008).
$identidadTecnico = trim($_SESSION['nombre'] ?? '');

try {
    $stmtUsuario = $pdo->prepare("SELECT alias_tecnico FROM usuarios WHERE id = ? LIMIT 1");
    $stmtUsuario->execute([$_SESSION['user_id'] ?? 0]);
    $alias = $stmtUsuario->fetchColumn();
    if (!empty($alias)) {
        $identidadTecnico = trim($alias);
    }
} catch (\Throwable $e) {
    // Si la migración 008 (columna alias_tecnico) todavía no se aplicó en
    // este entorno, seguimos con usuarios.nombre en vez de tronar.
    error_log('No se pudo leer alias_tecnico (¿falta aplicar la migración 008?): ' . $e->getMessage());
}

if ($identidadTecnico === '') {
    echo json_encode(['success' => false, 'error' => 'No se pudo determinar tu identidad de técnico.']);
    exit();
}

$action = $_GET['action'] ?? 'resumen';

if ($action !== 'resumen') {
    echo json_encode(['success' => false, 'error' => 'Acción no reconocida.']);
    exit();
}

// --- 2. INCIDENCIAS DEL PERIODO SELECCIONADO (para los KPIs) ---
//
// El filtro SQL por tecnico (LIKE) es solo una pre-selección eficiente;
// la pertenencia real se decide en PHP con tecnicoCoincide() (comparación
// exacta tras separar por "/"), porque aquí SÍ importa no filtrarse por
// una coincidencia parcial de texto.
$rango = construirFiltros($conn, 'i', 'fecha', null, null, $identidadTecnico);
$filtros_where = $rango['where'];

$sqlPeriodo = "SELECT id, fecha, estatus, tecnico, sucursal FROM incidencias i {$filtros_where}";
$candidatasPeriodo = ejecutarConsulta($conn, $sqlPeriodo);
$misIncidenciasPeriodo = array_values(array_filter(
    $candidatasPeriodo,
    fn($f) => tecnicoCoincide($identidadTecnico, $f['tecnico'] ?? '')
));

$analisis = analizarTiemposIncidencias($pdo, $misIncidenciasPeriodo);
$agregado = $analisis['agregado'];

$idsMios = array_flip(array_map(fn($f) => (string)$f['id'], $misIncidenciasPeriodo));
$reincidenciasMias = count(array_filter(
    calcularReincidencias($conn, $filtros_where)['ids'],
    fn($id) => isset($idsMios[$id])
));

$asignadas = count($misIncidenciasPeriodo);
$completadas = 0;
foreach ($misIncidenciasPeriodo as $f) {
    if (in_array(strtolower(trim($f['estatus'] ?? '')), ESTADOS_CERRADOS, true)) {
        $completadas++;
    }
}

// --- 3. TENDENCIA VS. PERIODO ANTERIOR EQUIVALENTE ---
$dtIni = new DateTime($rango['fecha_inicio']);
$dtFin = new DateTime($rango['fecha_fin']);
$dias_periodo = (int)$dtIni->diff($dtFin)->format('%a') + 1;
$prevFin = (clone $dtIni)->modify('-1 day')->format('Y-m-d');
$prevIni = (clone $dtIni)->modify('-' . $dias_periodo . ' days')->format('Y-m-d');
$rango_anterior = construirFiltros($conn, 'i', 'fecha', $prevIni, $prevFin, $identidadTecnico);
$candidatasAnterior = ejecutarConsulta($conn, "SELECT tecnico FROM incidencias i {$rango_anterior['where']}");
$totalAnterior = count(array_filter(
    $candidatasAnterior,
    fn($f) => tecnicoCoincide($identidadTecnico, $f['tecnico'] ?? '')
));
$tendencia = $totalAnterior > 0
    ? round((($asignadas - $totalAnterior) / $totalAnterior) * 100, 1)
    : ($asignadas > 0 ? 100 : 0);

// --- 4. BACKLOG ACTUAL (sin filtro de fecha: "lo que tengo pendiente hoy") ---
$identidadEscapada = $conn->real_escape_string($identidadTecnico);
$sqlBacklog = "
    SELECT id, numero_incidente, cliente, sucursal, estatus, fecha, tecnico
    FROM incidencias
    WHERE tecnico LIKE '%{$identidadEscapada}%'
      AND LOWER(estatus) NOT IN ('completado', 'cerrado con factura', 'cerrado sin factura', 'resuelto')
    ORDER BY fecha ASC
    LIMIT 200
";
$candidatasBacklog = ejecutarConsulta($conn, $sqlBacklog);
$backlog = array_values(array_filter(
    $candidatasBacklog,
    fn($f) => tecnicoCoincide($identidadTecnico, $f['tecnico'] ?? '')
));

$ahora = time();
$vencidas = 0;
foreach ($backlog as $b) {
    $ts = strtotime($b['fecha']);
    if ($ts !== false && ($ahora - $ts) > (SLA_CIERRE_HORAS * 3600)) {
        $vencidas++;
    }
}

// --- 5. EVOLUCIÓN MENSUAL (últimos 12 meses, incidencias cerradas propias) ---
$sqlHistorico = "
    SELECT fecha, estatus, tecnico
    FROM incidencias
    WHERE tecnico LIKE '%{$identidadEscapada}%'
      AND fecha >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
";
$candidatasHistorico = ejecutarConsulta($conn, $sqlHistorico);
$misHistorico = array_filter(
    $candidatasHistorico,
    fn($f) => tecnicoCoincide($identidadTecnico, $f['tecnico'] ?? '')
);

$porMes = [];
foreach ($misHistorico as $f) {
    if (!in_array(strtolower(trim($f['estatus'] ?? '')), ESTADOS_CERRADOS, true)) continue;
    $mes = substr((string)$f['fecha'], 0, 7);
    $porMes[$mes] = ($porMes[$mes] ?? 0) + 1;
}
ksort($porMes);
$evolucionMensual = [];
foreach ($porMes as $mes => $cantidad) {
    $evolucionMensual[] = ['mes' => $mes, 'cantidad' => $cantidad];
}

// --- 6. INSIGHTS PERSONALIZADOS (sin comparar contra otros técnicos) ---
$insights = [];

if ($totalAnterior > 0) {
    $comparativo = $tendencia > 0 ? 'más' : ($tendencia < 0 ? 'menos' : 'la misma cantidad de');
    $insights[] = "Se te asignaron {$asignadas} incidencias este periodo, {$comparativo} que en el periodo anterior ({$totalAnterior}).";
}

if ($agregado['sla_cierre_pct'] !== null) {
    $insights[] = "El {$agregado['sla_cierre_pct']}% de tus incidencias cerradas se atendieron dentro de los 7 días esperados, con base en {$agregado['muestras_cierre']} de {$asignadas} incidencias con historial.";
}

if ($reincidenciasMias > 0 && $asignadas > 0) {
    $pctReincidencias = round(($reincidenciasMias / $asignadas) * 100, 1);
    $insights[] = "{$reincidenciasMias} de tus incidencias fueron reincidencias (el mismo equipo volvió a fallar de algo parecido en menos de " . REINCIDENCIA_VENTANA_DIAS . " días; {$pctReincidencias}%). Vale la pena revisar si falta documentar la causa raíz antes de cerrar.";
}

if ($vencidas > 0) {
    $insights[] = "Tienes {$vencidas} incidencia(s) abiertas con más de 7 días sin cerrarse. Conviene revisarlas pronto.";
}

if (empty($insights)) {
    $insights[] = "Aún no hay suficiente historial en este periodo para generar lecturas automáticas.";
}

echo json_encode([
    'success' => true,
    'data' => [
        'identidad' => $identidadTecnico,
        'resumen' => [
            'asignadas' => $asignadas,
            'completadas' => $completadas,
            'pendientes_actual' => count($backlog),
            'vencidas' => $vencidas,
            'tendencia_incidencias' => $tendencia,
            'respuesta_mediana_horas' => $agregado['respuesta_mediana_horas'],
            'muestras_respuesta' => $agregado['muestras_respuesta'],
            'cierre_mediana_horas' => $agregado['cierre_mediana_horas'],
            'muestras_cierre' => $agregado['muestras_cierre'],
            'sla_cierre_pct' => $agregado['sla_cierre_pct'],
            'reincidencias' => $reincidenciasMias,
        ],
        'evolucion_mensual' => $evolucionMensual,
        'insights' => $insights,
        'pendientes' => array_map(fn($b) => [
            'id' => $b['id'],
            'numero_incidente' => $b['numero_incidente'],
            'cliente' => $b['cliente'],
            'sucursal' => $b['sucursal'],
            'estatus' => $b['estatus'],
            'fecha' => $b['fecha'],
        ], $backlog),
    ],
]);

$conn->close();
