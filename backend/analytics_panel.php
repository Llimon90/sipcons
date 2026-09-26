<?php
// API del panel de analíticas de uso. Solo Programador (o usuarios listados
// en ANALYTICS_USUARIOS del .env). Responde 404 a cualquier otro usuario para
// no revelar que existe.
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../auth/analytics_acceso.php';
require_once __DIR__ . '/lib/analytics_helpers.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

requireAnaliticas();

$action = $_GET['action'] ?? '';
$resp = ['success' => false, 'error' => 'Acción no válida.'];

try {
    switch ($action) {
        case 'resumen':   $data = analyticsResumen($pdo, $_GET); break;
        case 'modulos':   $data = analyticsModulos($pdo, $_GET); break;
        case 'elementos': $data = analyticsElementos($pdo, $_GET); break;
        case 'flujos':    $data = analyticsFlujos($pdo, $_GET); break;
        case 'friccion':  $data = analyticsFriccion($pdo, $_GET); break;
        case 'usuarios':  $data = analyticsUsuarios($pdo, $_GET); break;
        case 'usuario':   $data = analyticsUsuarioDetalle($pdo, $_GET); break;
        case 'sesion':    $data = analyticsSesionDetalle($pdo, (string)($_GET['sesion_id'] ?? '')); break;
        case 'adopcion':  $data = analyticsAdopcion($pdo, $_GET); break;
        case 'hallazgos': $data = analyticsHallazgos($pdo, $_GET); break;
        case 'catalogo':
            $data = [
                'roles' => array_column(analyticsSql($pdo, 'SELECT DISTINCT rol FROM analytics_eventos WHERE rol IS NOT NULL ORDER BY rol'), 'rol'),
                'usuarios' => analyticsSql($pdo, 'SELECT usuario_id, MAX(usuario_nombre) AS nombre, MAX(usuario) AS usuario FROM analytics_eventos WHERE usuario_id IS NOT NULL GROUP BY usuario_id ORDER BY nombre'),
                'total_eventos' => (int)(analyticsSql($pdo, 'SELECT COUNT(*) AS n FROM analytics_eventos')[0]['n'] ?? 0),
                'primer_evento' => analyticsSql($pdo, 'SELECT MIN(creado_en) AS d FROM analytics_eventos')[0]['d'] ?? null,
            ];
            break;
        default:          $data = null;
    }

    if ($data !== null) {
        $resp = isset($data['error']) ? ['success' => false, 'error' => $data['error']] : ['success' => true, 'data' => $data];
    }
} catch (\Throwable $e) {
    error_log('Analíticas (panel) [' . $action . ']: ' . $e->getMessage());
    $tablaFalta = stripos($e->getMessage(), 'analytics_eventos') !== false;
    $resp = ['success' => false, 'error' => $tablaFalta
        ? 'La tabla analytics_eventos no existe todavía: aplica la migración 011.'
        : 'No se pudo calcular esta vista.'];
}

echo json_encode($resp, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
