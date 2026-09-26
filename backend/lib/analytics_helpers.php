<?php
// Analíticas de uso del portal: utilidades de registro y de cálculo.
// Lo usan backend/analytics_registrar.php (escritura) y
// backend/analytics_panel.php (lectura, solo rol Programador).
//
// El SQL es deliberadamente portable (sin funciones exclusivas de MySQL) y las
// horas/días de la semana se calculan en PHP.

require_once __DIR__ . '/../../auth/permisos.php'; // ROL_ACCESO_TOTAL

const ANALYTICS_TIPOS = [
    'pageview', 'carga', 'permanencia', 'click', 'tab', 'submit', 'cambio',
    'busqueda', 'error_js', 'api', 'rage_click', 'dead_click',
    'validacion_fallida', 'acceso_denegado',
];

// SQL que cuenta como "error": fallos de JavaScript y llamadas a la API con
// respuesta >= 400 o sin respuesta (extra = 0).
const ANALYTICS_SQL_ES_ERROR = "(tipo = 'error_js' OR (tipo = 'api' AND (extra >= 400 OR extra = 0)))";

// ---------------------------------------------------------------- catálogo

function analyticsCatalogoPaginas(): array {
    return [
        'index.html'              => ['Inicio', 'Menú principal'],
        'incidencias.html'        => ['Incidencias', 'Registrar incidencia'],
        'incidencias_general.html'=> ['Incidencias', 'Incidencias (vista general)'],
        'detalle.html'            => ['Incidencias', 'Detalle / edición de incidencia'],
        'reportes.html'           => ['Buscar incidencias', 'Búsqueda de incidencias'],
        'clientes.html'           => ['Clientes', 'Listado de clientes'],
        'perfil-cliente.html'     => ['Clientes', 'Perfil de cliente'],
        'usuarios.html'           => ['Usuarios', 'Gestión de usuarios'],
        'historial.html'          => ['Historial', 'Historial de cambios'],
        'ventas.html'             => ['Ventas', 'Registro de venta'],
        'admin-ventas.html'       => ['Ventas', 'Administración de ventas'],
        'consulta_ventas.html'    => ['Ventas', 'Consulta de ventas'],
        'detalle-venta.html'      => ['Ventas', 'Detalle de venta'],
        'detalles-venta.html'     => ['Ventas', 'Detalles de venta'],
        'informes.html'           => ['Estadísticas', 'Panel de estadísticas'],
        'dashboard-tecnico.html'  => ['Métricas', 'Métricas personales'],
        'ajustes.html'            => ['Ajustes', 'Ajustes y privilegios'],
        'soporte.html'            => ['Soporte', 'Soporte y FAQs'],
        'adm_bd.html'             => ['Administración BD', 'Administración de base de datos'],
    ];
}

function analyticsInfoPagina($pagina): array {
    $cat = analyticsCatalogoPaginas();
    if (isset($cat[$pagina])) {
        return ['modulo' => $cat[$pagina][0], 'nombre' => $cat[$pagina][1]];
    }
    return ['modulo' => 'Otros', 'nombre' => (string)$pagina];
}

function analyticsModulosCatalogo(): array {
    $mods = [];
    foreach (analyticsCatalogoPaginas() as $info) {
        if (!in_array($info[0], ['Inicio', 'Administración BD'], true)) $mods[$info[0]] = true;
    }
    return array_keys($mods);
}

function analyticsParseUA(string $ua): array {
    $dispositivo = 'escritorio';
    if (preg_match('/iPad|Tablet/i', $ua) || (stripos($ua, 'Android') !== false && stripos($ua, 'Mobile') === false)) {
        $dispositivo = 'tablet';
    } elseif (preg_match('/Mobi|iPhone|iPod/i', $ua)) {
        $dispositivo = 'movil';
    }

    if (preg_match('/Edg(e|A|iOS)?\//i', $ua)) $navegador = 'Edge';
    elseif (preg_match('/OPR\/|Opera/i', $ua)) $navegador = 'Opera';
    elseif (stripos($ua, 'SamsungBrowser') !== false) $navegador = 'Samsung';
    elseif (preg_match('/Firefox|FxiOS/i', $ua)) $navegador = 'Firefox';
    elseif (preg_match('/Chrome|CriOS/i', $ua)) $navegador = 'Chrome';
    elseif (stripos($ua, 'Safari') !== false) $navegador = 'Safari';
    elseif (preg_match('/MSIE|Trident/i', $ua)) $navegador = 'IE';
    else $navegador = 'Otro';

    if (preg_match('/Windows/i', $ua)) $sistema = 'Windows';
    elseif (preg_match('/Android/i', $ua)) $sistema = 'Android';
    elseif (preg_match('/iPhone|iPad|iPod|iOS/i', $ua)) $sistema = 'iOS';
    elseif (preg_match('/Mac OS X|Macintosh/i', $ua)) $sistema = 'macOS';
    elseif (preg_match('/CrOS/i', $ua)) $sistema = 'ChromeOS';
    elseif (preg_match('/Linux/i', $ua)) $sistema = 'Linux';
    else $sistema = 'Otro';

    return ['dispositivo' => $dispositivo, 'navegador' => $navegador, 'sistema' => $sistema];
}

function analyticsLimpiar($valor, int $max): ?string {
    if ($valor === null || is_array($valor) || is_object($valor)) return null;
    $s = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string)$valor);
    $s = trim((string)$s);
    if ($s === '') return null;
    return mb_substr($s, 0, $max);
}

// ------------------------------------------------------- rango y filtros

function analyticsRango(array $get): array {
    $hoy = date('Y-m-d');
    $rango = (string)($get['rango'] ?? '30');
    $valida = fn($f) => is_string($f) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $f) && strtotime($f) !== false;

    if ($rango === 'custom' && $valida($get['desde'] ?? null) && $valida($get['hasta'] ?? null)) {
        $desde = $get['desde'];
        $hasta = $get['hasta'];
        if ($desde > $hasta) { [$desde, $hasta] = [$hasta, $desde]; }
        if ((strtotime($hasta) - strtotime($desde)) / 86400 > 800) {
            $desde = date('Y-m-d', strtotime($hasta . ' -800 days'));
        }
    } else {
        $n = max(1, min(730, (int)$rango ?: 30));
        $hasta = $hoy;
        $desde = date('Y-m-d', strtotime($hoy . ' -' . ($n - 1) . ' days'));
    }

    $dias = (int)round((strtotime($hasta) - strtotime($desde)) / 86400) + 1;
    $prevHasta = date('Y-m-d', strtotime($desde . ' -1 day'));
    $prevDesde = date('Y-m-d', strtotime($prevHasta . ' -' . ($dias - 1) . ' days'));

    return ['desde' => $desde, 'hasta' => $hasta, 'dias' => $dias, 'prev_desde' => $prevDesde, 'prev_hasta' => $prevHasta];
}

/**
 * Condición WHERE (sin la palabra WHERE) y parámetros posicionales.
 * $o: desde, hasta, incluir_programador, rol, usuario_id
 */
function analyticsFiltro(array $o): array {
    $sql = 'creado_en >= ? AND creado_en < ?';
    $params = [$o['desde'] . ' 00:00:00', date('Y-m-d', strtotime($o['hasta'] . ' +1 day')) . ' 00:00:00'];

    if (empty($o['incluir_programador'])) {
        $sql .= " AND COALESCE(rol, '') <> ?";
        $params[] = ROL_ACCESO_TOTAL;
    }
    if (!empty($o['rol'])) {
        $sql .= ' AND rol = ?';
        $params[] = (string)$o['rol'];
    }
    if (!empty($o['usuario_id'])) {
        $sql .= ' AND usuario_id = ?';
        $params[] = (int)$o['usuario_id'];
    }
    return ['sql' => $sql, 'params' => $params];
}

function analyticsFiltroDesdeGet(array $get, ?array $rango = null): array {
    $rango = $rango ?? analyticsRango($get);
    return analyticsFiltro([
        'desde' => $rango['desde'],
        'hasta' => $rango['hasta'],
        'incluir_programador' => !empty($get['incluir_programador']) && $get['incluir_programador'] !== '0',
        'rol' => $get['rol'] ?? '',
        'usuario_id' => $get['usuario_id'] ?? null,
    ]);
}

function analyticsSql(PDO $pdo, string $sql, array $params = []): array {
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

// ------------------------------------------------------------ estadística

function analyticsPercentil(array $valores, float $p) {
    $n = count($valores);
    if ($n === 0) return null;
    sort($valores);
    $i = (int)ceil(($p / 100) * $n) - 1;
    return $valores[max(0, min($n - 1, $i))];
}

function analyticsPromedio(array $v) {
    return count($v) > 0 ? array_sum($v) / count($v) : null;
}

function analyticsPct($parte, $total, int $dec = 1) {
    return $total > 0 ? round(($parte / $total) * 100, $dec) : null;
}

function analyticsTendencia($actual, $previo) {
    if ($previo > 0) return round((($actual - $previo) / $previo) * 100, 1);
    return $actual > 0 ? 100.0 : 0.0;
}

// -------------------------------------------------- visitas y sesiones

/**
 * Una fila por visita (carga de página). Es la base de casi todo el panel.
 */
function analyticsVisitas(PDO $pdo, array $f): array {
    $sql = "SELECT visita_id,
                   MIN(sesion_id) AS sesion_id,
                   MIN(usuario_id) AS usuario_id,
                   MIN(rol) AS rol,
                   MIN(pagina) AS pagina,
                   MIN(creado_en) AS ini,
                   SUM(CASE WHEN tipo = 'pageview' THEN 1 ELSE 0 END) AS pv,
                   SUM(CASE WHEN tipo = 'permanencia' THEN COALESCE(valor, 0) ELSE 0 END) AS act_ms,
                   MAX(CASE WHEN tipo = 'permanencia' THEN COALESCE(extra, 0) ELSE 0 END) AS scroll,
                   SUM(CASE WHEN tipo IN ('click', 'tab') THEN 1 ELSE 0 END) AS clics,
                   SUM(CASE WHEN " . ANALYTICS_SQL_ES_ERROR . " THEN 1 ELSE 0 END) AS errores,
                   MAX(CASE WHEN tipo = 'carga' THEN valor ELSE NULL END) AS carga_ms
            FROM analytics_eventos
            WHERE {$f['sql']}
            GROUP BY visita_id";
    $visitas = [];
    foreach (analyticsSql($pdo, $sql, $f['params']) as $r) {
        if ((int)$r['pv'] < 1) continue;
        $r['usuario_id'] = $r['usuario_id'] !== null ? (int)$r['usuario_id'] : null;
        $r['act_ms'] = (int)$r['act_ms'];
        $r['scroll'] = (int)$r['scroll'];
        $r['clics'] = (int)$r['clics'];
        $r['errores'] = (int)$r['errores'];
        $r['carga_ms'] = $r['carga_ms'] !== null ? (int)$r['carga_ms'] : null;
        $r['ts'] = (int)strtotime($r['ini']);
        $visitas[] = $r;
    }
    usort($visitas, function ($a, $b) {
        return [$a['sesion_id'], $a['ts']] <=> [$b['sesion_id'], $b['ts']];
    });
    return $visitas;
}

function analyticsSesiones(array $visitas): array {
    $ses = [];
    foreach ($visitas as $v) {
        $id = $v['sesion_id'];
        if (!isset($ses[$id])) {
            $ses[$id] = [
                'sesion_id' => $id, 'usuario_id' => $v['usuario_id'], 'rol' => $v['rol'],
                'ini' => $v['ts'], 'fin' => $v['ts'], 'visitas' => [],
                'clics' => 0, 'act_ms' => 0, 'errores' => 0,
            ];
        }
        $ses[$id]['visitas'][] = $v;
        $ses[$id]['clics'] += $v['clics'];
        $ses[$id]['act_ms'] += $v['act_ms'];
        $ses[$id]['errores'] += $v['errores'];
        $ses[$id]['fin'] = max($ses[$id]['fin'], $v['ts'] + (int)round($v['act_ms'] / 1000));
    }
    foreach ($ses as &$s) {
        $s['rebote'] = count($s['visitas']) === 1 && $s['act_ms'] < 10000 && $s['clics'] === 0;
        $s['duracion_s'] = max(0, $s['fin'] - $s['ini']);
    }
    unset($s);
    return array_values($ses);
}

function analyticsUsuariosUnicos(array $visitas): int {
    $u = [];
    foreach ($visitas as $v) {
        if ($v['usuario_id'] !== null) $u[$v['usuario_id']] = true;
    }
    return count($u);
}

// ----------------------------------------------------------------- resumen

function analyticsKpiBasico(PDO $pdo, array $f): array {
    $r = analyticsSql($pdo, "SELECT
            SUM(CASE WHEN tipo = 'pageview' THEN 1 ELSE 0 END) AS visitas,
            COUNT(DISTINCT sesion_id) AS sesiones,
            COUNT(DISTINCT usuario_id) AS usuarios,
            SUM(CASE WHEN tipo = 'permanencia' THEN COALESCE(valor, 0) ELSE 0 END) AS act_ms
        FROM analytics_eventos WHERE {$f['sql']}", $f['params'])[0] ?? [];
    return [
        'visitas' => (int)($r['visitas'] ?? 0),
        'sesiones' => (int)($r['sesiones'] ?? 0),
        'usuarios' => (int)($r['usuarios'] ?? 0),
        'act_ms' => (int)($r['act_ms'] ?? 0),
    ];
}

function analyticsDistribucion(PDO $pdo, array $f, string $columna): array {
    $permitidas = ['dispositivo', 'navegador', 'sistema', 'rol'];
    if (!in_array($columna, $permitidas, true)) return [];
    $rows = analyticsSql($pdo, "SELECT {$columna} AS nombre, COUNT(DISTINCT sesion_id) AS sesiones
        FROM analytics_eventos WHERE {$f['sql']} AND tipo = 'pageview'
        GROUP BY {$columna} ORDER BY sesiones DESC", $f['params']);
    return array_map(fn($r) => ['nombre' => $r['nombre'] ?: 'Desconocido', 'sesiones' => (int)$r['sesiones']], $rows);
}

function analyticsResumen(PDO $pdo, array $get): array {
    $rango = analyticsRango($get);
    $f = analyticsFiltroDesdeGet($get, $rango);
    $visitas = analyticsVisitas($pdo, $f);
    $sesiones = analyticsSesiones($visitas);

    $nSes = count($sesiones);
    $rebotes = count(array_filter($sesiones, fn($s) => $s['rebote']));
    $actTotal = array_sum(array_column($visitas, 'act_ms'));
    $clics = array_sum(array_column($visitas, 'clics'));
    $errores = array_sum(array_column($visitas, 'errores'));
    $duraciones = array_column($sesiones, 'duracion_s');

    $prevFiltro = analyticsFiltroDesdeGet($get, ['desde' => $rango['prev_desde'], 'hasta' => $rango['prev_hasta']]);
    $prev = analyticsKpiBasico($pdo, $prevFiltro);

    // Serie diaria
    $dias = [];
    for ($t = strtotime($rango['desde']); $t <= strtotime($rango['hasta']); $t += 86400) {
        $dias[date('Y-m-d', $t)] = ['fecha' => date('Y-m-d', $t), 'visitas' => 0, 'usuarios' => [], 'sesiones' => [], 'clics' => 0, 'errores' => 0];
    }
    $heat = array_fill(0, 7, array_fill(0, 24, 0));
    foreach ($visitas as $v) {
        $d = date('Y-m-d', $v['ts']);
        if (isset($dias[$d])) {
            $dias[$d]['visitas']++;
            $dias[$d]['clics'] += $v['clics'];
            $dias[$d]['errores'] += $v['errores'];
            $dias[$d]['sesiones'][$v['sesion_id']] = true;
            if ($v['usuario_id'] !== null) $dias[$d]['usuarios'][$v['usuario_id']] = true;
        }
        $heat[(int)date('N', $v['ts']) - 1][(int)date('G', $v['ts'])]++;
    }
    $serie = array_values(array_map(fn($d) => [
        'fecha' => $d['fecha'], 'visitas' => $d['visitas'], 'usuarios' => count($d['usuarios']),
        'sesiones' => count($d['sesiones']), 'clics' => $d['clics'], 'errores' => $d['errores'],
    ], $dias));

    return [
        'rango' => $rango,
        'kpis' => [
            'visitas' => count($visitas),
            'sesiones' => $nSes,
            'usuarios' => analyticsUsuariosUnicos($visitas),
            'clics' => $clics,
            'errores' => $errores,
            'act_total_min' => round($actTotal / 60000, 1),
            'act_prom_sesion_s' => $nSes > 0 ? round(($actTotal / 1000) / $nSes) : null,
            'duracion_prom_sesion_s' => $nSes > 0 ? round(array_sum($duraciones) / $nSes) : null,
            'paginas_por_sesion' => $nSes > 0 ? round(count($visitas) / $nSes, 1) : null,
            'clics_por_sesion' => $nSes > 0 ? round($clics / $nSes, 1) : null,
            'rebote_pct' => analyticsPct($rebotes, $nSes),
        ],
        'tendencias' => [
            'visitas' => analyticsTendencia(count($visitas), $prev['visitas']),
            'sesiones' => analyticsTendencia($nSes, $prev['sesiones']),
            'usuarios' => analyticsTendencia(analyticsUsuariosUnicos($visitas), $prev['usuarios']),
            'act' => analyticsTendencia($actTotal, $prev['act_ms']),
        ],
        'previo' => $prev,
        'serie' => $serie,
        'heatmap' => $heat,
        'dispositivos' => analyticsDistribucion($pdo, $f, 'dispositivo'),
        'navegadores' => analyticsDistribucion($pdo, $f, 'navegador'),
        'sistemas' => analyticsDistribucion($pdo, $f, 'sistema'),
        'roles' => analyticsDistribucion($pdo, $f, 'rol'),
    ];
}

// ----------------------------------------------- módulos y secciones

function analyticsModulos(PDO $pdo, array $get): array {
    $f = analyticsFiltroDesdeGet($get);
    $visitas = analyticsVisitas($pdo, $f);
    $sesiones = analyticsSesiones($visitas);

    $entradas = [];
    $salidas = [];
    foreach ($sesiones as $s) {
        $pri = $s['visitas'][0]['pagina'];
        $ult = $s['visitas'][count($s['visitas']) - 1]['pagina'];
        $entradas[$pri] = ($entradas[$pri] ?? 0) + 1;
        $salidas[$ult] = ($salidas[$ult] ?? 0) + 1;
    }

    $pp = [];
    foreach ($visitas as $v) {
        $p = $v['pagina'] ?? '(desconocida)';
        if (!isset($pp[$p])) {
            $pp[$p] = ['visitas' => 0, 'usuarios' => [], 'acts' => [], 'scrolls' => [], 'clics' => 0, 'errores' => 0, 'cargas' => []];
        }
        $pp[$p]['visitas']++;
        if ($v['usuario_id'] !== null) $pp[$p]['usuarios'][$v['usuario_id']] = true;
        if ($v['act_ms'] > 0) $pp[$p]['acts'][] = $v['act_ms'] / 1000;
        if ($v['scroll'] > 0) $pp[$p]['scrolls'][] = $v['scroll'];
        $pp[$p]['clics'] += $v['clics'];
        $pp[$p]['errores'] += $v['errores'];
        if ($v['carga_ms'] !== null && $v['carga_ms'] > 0) $pp[$p]['cargas'][] = $v['carga_ms'];
    }

    $paginas = [];
    $mods = [];
    foreach ($pp as $pagina => $d) {
        $info = analyticsInfoPagina($pagina);
        $ent = $entradas[$pagina] ?? 0;
        $sal = $salidas[$pagina] ?? 0;
        $paginas[] = [
            'pagina' => $pagina, 'nombre' => $info['nombre'], 'modulo' => $info['modulo'],
            'visitas' => $d['visitas'], 'usuarios' => count($d['usuarios']),
            'tiempo_mediano_s' => ($m = analyticsPercentil($d['acts'], 50)) !== null ? round($m) : null,
            'tiempo_promedio_s' => ($m = analyticsPromedio($d['acts'])) !== null ? round($m) : null,
            'tiempo_total_min' => round(array_sum($d['acts']) / 60, 1),
            'scroll_prom' => ($m = analyticsPromedio($d['scrolls'])) !== null ? round($m) : null,
            'clics_por_visita' => round($d['clics'] / $d['visitas'], 1),
            'entradas' => $ent, 'salidas' => $sal,
            'tasa_salida_pct' => analyticsPct($sal, $d['visitas']),
            'errores' => $d['errores'],
            'carga_p50_ms' => analyticsPercentil($d['cargas'], 50),
            'carga_p95_ms' => analyticsPercentil($d['cargas'], 95),
        ];

        $m = $info['modulo'];
        if (!isset($mods[$m])) $mods[$m] = ['modulo' => $m, 'visitas' => 0, 'usuarios' => [], 'act_s' => 0, 'clics' => 0, 'errores' => 0, 'paginas' => 0];
        $mods[$m]['visitas'] += $d['visitas'];
        $mods[$m]['act_s'] += array_sum($d['acts']);
        $mods[$m]['clics'] += $d['clics'];
        $mods[$m]['errores'] += $d['errores'];
        $mods[$m]['paginas']++;
        foreach ($d['usuarios'] as $uid => $_) $mods[$m]['usuarios'][$uid] = true;
    }
    usort($paginas, fn($a, $b) => $b['visitas'] <=> $a['visitas']);

    $totalVisitas = count($visitas);
    $modulos = array_map(fn($m) => [
        'modulo' => $m['modulo'], 'visitas' => $m['visitas'], 'usuarios' => count($m['usuarios']),
        'pct_visitas' => analyticsPct($m['visitas'], $totalVisitas),
        'tiempo_total_min' => round($m['act_s'] / 60, 1),
        'tiempo_por_visita_s' => $m['visitas'] > 0 ? round($m['act_s'] / $m['visitas']) : null,
        'clics' => $m['clics'], 'errores' => $m['errores'], 'paginas' => $m['paginas'],
    ], array_values($mods));
    usort($modulos, fn($a, $b) => $b['visitas'] <=> $a['visitas']);

    // Secciones internas: pestañas dentro de una página
    $tabs = analyticsSql($pdo, "SELECT pagina, elemento, COUNT(*) AS n, COUNT(DISTINCT usuario_id) AS usuarios
        FROM analytics_eventos WHERE {$f['sql']} AND tipo = 'tab'
        GROUP BY pagina, elemento ORDER BY n DESC LIMIT 60", $f['params']);
    $tabs = array_map(function ($t) {
        return ['pagina' => $t['pagina'], 'nombre_pagina' => analyticsInfoPagina($t['pagina'])['nombre'],
                'elemento' => $t['elemento'], 'n' => (int)$t['n'], 'usuarios' => (int)$t['usuarios']];
    }, $tabs);

    return ['total_visitas' => $totalVisitas, 'paginas' => $paginas, 'modulos' => $modulos, 'tabs' => $tabs];
}

// ------------------------------------------- elementos y fricción

function analyticsElementos(PDO $pdo, array $get): array {
    $f = analyticsFiltroDesdeGet($get);
    $top = analyticsSql($pdo, "SELECT pagina, tipo, elemento, COUNT(*) AS n, COUNT(DISTINCT usuario_id) AS usuarios
        FROM analytics_eventos WHERE {$f['sql']} AND tipo IN ('click', 'tab', 'cambio', 'submit', 'busqueda')
        GROUP BY pagina, tipo, elemento ORDER BY n DESC LIMIT 150", $f['params']);
    $frustracion = analyticsSql($pdo, "SELECT pagina, tipo, elemento, COUNT(*) AS n, COUNT(DISTINCT usuario_id) AS usuarios, MAX(creado_en) AS ultimo
        FROM analytics_eventos WHERE {$f['sql']} AND tipo IN ('rage_click', 'dead_click', 'validacion_fallida')
        GROUP BY pagina, tipo, elemento ORDER BY n DESC LIMIT 80", $f['params']);
    $cambios = analyticsSql($pdo, "SELECT pagina, elemento, detalle, COUNT(*) AS n
        FROM analytics_eventos WHERE {$f['sql']} AND tipo = 'cambio'
        GROUP BY pagina, elemento, detalle ORDER BY n DESC LIMIT 100", $f['params']);

    $fmt = function ($r) {
        $r['nombre_pagina'] = analyticsInfoPagina($r['pagina'])['nombre'];
        $r['n'] = (int)$r['n'];
        if (isset($r['usuarios'])) $r['usuarios'] = (int)$r['usuarios'];
        return $r;
    };
    return [
        'top' => array_map($fmt, $top),
        'frustracion' => array_map($fmt, $frustracion),
        'filtros_usados' => array_map($fmt, $cambios),
    ];
}

function analyticsFriccion(PDO $pdo, array $get): array {
    $f = analyticsFiltroDesdeGet($get);

    $js = analyticsSql($pdo, "SELECT elemento AS mensaje, pagina, MAX(detalle) AS origen, COUNT(*) AS n,
            COUNT(DISTINCT usuario_id) AS usuarios, MAX(creado_en) AS ultimo
        FROM analytics_eventos WHERE {$f['sql']} AND tipo = 'error_js'
        GROUP BY elemento, pagina ORDER BY n DESC LIMIT 60", $f['params']);
    $js = array_map(fn($r) => [
        'mensaje' => $r['mensaje'], 'pagina' => $r['pagina'], 'nombre_pagina' => analyticsInfoPagina($r['pagina'])['nombre'],
        'origen' => $r['origen'], 'n' => (int)$r['n'], 'usuarios' => (int)$r['usuarios'], 'ultimo' => $r['ultimo'],
    ], $js);

    $api = analyticsSql($pdo, "SELECT elemento, valor, extra FROM analytics_eventos
        WHERE {$f['sql']} AND tipo = 'api' LIMIT 200000", $f['params']);
    $ep = [];
    foreach ($api as $r) {
        $k = $r['elemento'] ?? '(desconocido)';
        if (!isset($ep[$k])) $ep[$k] = ['ms' => [], 'errores' => 0, 'estatus' => []];
        $ep[$k]['ms'][] = (int)$r['valor'];
        $status = (int)$r['extra'];
        if ($status >= 400 || $status === 0) {
            $ep[$k]['errores']++;
            $ep[$k]['estatus'][$status] = ($ep[$k]['estatus'][$status] ?? 0) + 1;
        }
    }
    $endpoints = [];
    foreach ($ep as $nombre => $d) {
        $n = count($d['ms']);
        arsort($d['estatus']);
        $endpoints[] = [
            'endpoint' => $nombre, 'llamadas' => $n, 'errores' => $d['errores'],
            'error_pct' => analyticsPct($d['errores'], $n),
            'p50_ms' => analyticsPercentil($d['ms'], 50), 'p95_ms' => analyticsPercentil($d['ms'], 95),
            'max_ms' => max($d['ms']),
            'estatus' => array_map(fn($c, $s) => ['estatus' => (int)$s, 'n' => $c], $d['estatus'], array_keys($d['estatus'])),
        ];
    }
    usort($endpoints, fn($a, $b) => [$b['errores'], $b['p95_ms']] <=> [$a['errores'], $a['p95_ms']]);

    $mod = analyticsModulos($pdo, $get);
    $cargas = [];
    foreach ($mod['paginas'] as $p) {
        if ($p['carga_p50_ms'] !== null) {
            $cargas[] = ['pagina' => $p['pagina'], 'nombre' => $p['nombre'], 'visitas' => $p['visitas'],
                         'p50_ms' => $p['carga_p50_ms'], 'p95_ms' => $p['carga_p95_ms']];
        }
    }
    usort($cargas, fn($a, $b) => $b['p95_ms'] <=> $a['p95_ms']);

    $accesos = analyticsSql($pdo, "SELECT elemento AS pagina, COUNT(*) AS n, COUNT(DISTINCT usuario_id) AS usuarios
        FROM analytics_eventos WHERE {$f['sql']} AND tipo = 'acceso_denegado'
        GROUP BY elemento ORDER BY n DESC LIMIT 30", $f['params']);

    $el = analyticsElementos($pdo, $get);

    return [
        'errores_js' => $js,
        'endpoints' => $endpoints,
        'cargas' => $cargas,
        'frustracion' => $el['frustracion'],
        'accesos_denegados' => array_map(fn($r) => ['pagina' => $r['pagina'], 'n' => (int)$r['n'], 'usuarios' => (int)$r['usuarios']], $accesos),
    ];
}

// --------------------------------------------------------------- flujos

function analyticsSecuenciaPaginas(array $sesion): array {
    $seq = [];
    foreach ($sesion['visitas'] as $v) {
        $p = $v['pagina'] ?? '(desconocida)';
        if (empty($seq) || $seq[count($seq) - 1] !== $p) $seq[] = $p;
    }
    return $seq;
}

function analyticsFlujos(PDO $pdo, array $get): array {
    $f = analyticsFiltroDesdeGet($get);
    $sesiones = analyticsSesiones(analyticsVisitas($pdo, $f));

    $trans = [];
    $ent = [];
    $sal = [];
    $rutas = [];
    $solo1 = 0;
    foreach ($sesiones as $s) {
        $seq = analyticsSecuenciaPaginas($s);
        $ent[$seq[0]] = ($ent[$seq[0]] ?? 0) + 1;
        $sal[$seq[count($seq) - 1]] = ($sal[$seq[count($seq) - 1]] ?? 0) + 1;
        if (count($seq) === 1) $solo1++;
        for ($i = 0; $i < count($seq) - 1; $i++) {
            $k = $seq[$i] . '|' . $seq[$i + 1];
            $trans[$k] = ($trans[$k] ?? 0) + 1;
        }
        for ($i = 0; $i < count($seq) - 2; $i++) {
            $k = $seq[$i] . '|' . $seq[$i + 1] . '|' . $seq[$i + 2];
            $rutas[$k] = ($rutas[$k] ?? 0) + 1;
        }
    }
    arsort($trans); arsort($rutas); arsort($ent); arsort($sal);

    $nombre = fn($p) => analyticsInfoPagina($p)['nombre'];
    $out = ['transiciones' => [], 'rutas' => [], 'entradas' => [], 'salidas' => []];
    foreach (array_slice($trans, 0, 30, true) as $k => $n) {
        [$a, $b] = explode('|', $k);
        $out['transiciones'][] = ['desde' => $a, 'hasta' => $b, 'nombre_desde' => $nombre($a), 'nombre_hasta' => $nombre($b), 'n' => $n];
    }
    foreach (array_slice($rutas, 0, 15, true) as $k => $n) {
        $ps = explode('|', $k);
        $out['rutas'][] = ['pasos' => array_map($nombre, $ps), 'n' => $n];
    }
    $totalSes = count($sesiones);
    foreach (array_slice($ent, 0, 12, true) as $p => $n) {
        $out['entradas'][] = ['pagina' => $p, 'nombre' => $nombre($p), 'n' => $n, 'pct' => analyticsPct($n, $totalSes)];
    }
    foreach (array_slice($sal, 0, 12, true) as $p => $n) {
        $out['salidas'][] = ['pagina' => $p, 'nombre' => $nombre($p), 'n' => $n, 'pct' => analyticsPct($n, $totalSes)];
    }
    $out['sesiones'] = $totalSes;
    $out['sesiones_una_pagina'] = $solo1;
    $out['embudos'] = analyticsEmbudos($pdo, $f);
    return $out;
}

function analyticsEmbudos(PDO $pdo, array $f): array {
    $defs = [
        ['id' => 'alta_incidencia', 'nombre' => 'Alta de incidencia', 'pasos' => [
            ['etiqueta' => 'Abre "Registrar incidencia"', 'tipo' => 'pageview', 'pagina' => 'incidencias.html'],
            ['etiqueta' => 'Envía el formulario', 'tipo' => 'submit', 'pagina' => 'incidencias.html', 'elemento' => 'new-incidencia-form'],
        ]],
        ['id' => 'edicion_incidencia', 'nombre' => 'Edición de incidencia', 'pasos' => [
            ['etiqueta' => 'Abre el detalle de una incidencia', 'tipo' => 'pageview', 'pagina' => 'detalle.html'],
            ['etiqueta' => 'Guarda cambios', 'tipo' => 'submit', 'pagina' => 'detalle.html', 'elemento' => 'form-editar'],
        ]],
        ['id' => 'busqueda_detalle', 'nombre' => 'Búsqueda → detalle', 'pasos' => [
            ['etiqueta' => 'Abre "Buscar incidencias"', 'tipo' => 'pageview', 'pagina' => 'reportes.html'],
            ['etiqueta' => 'Abre el detalle de una incidencia', 'tipo' => 'pageview', 'pagina' => 'detalle.html'],
        ]],
        ['id' => 'estadisticas', 'nombre' => 'Consulta de estadísticas', 'pasos' => [
            ['etiqueta' => 'Abre el panel de estadísticas', 'tipo' => 'pageview', 'pagina' => 'informes.html'],
            ['etiqueta' => 'Cambia de pestaña o vista', 'tipo' => 'tab', 'pagina' => 'informes.html'],
        ]],
    ];

    $eventos = analyticsSql($pdo, "SELECT sesion_id, usuario_id, tipo, pagina, elemento
        FROM analytics_eventos
        WHERE {$f['sql']} AND tipo IN ('pageview', 'submit', 'tab')
        ORDER BY sesion_id, creado_en, id LIMIT 300000", $f['params']);
    $porSesion = [];
    foreach ($eventos as $e) $porSesion[$e['sesion_id']][] = $e;

    $out = [];
    foreach ($defs as $def) {
        $n = count($def['pasos']);
        $ses = array_fill(0, $n, 0);
        $usr = array_fill(0, $n, []);
        foreach ($porSesion as $evs) {
            $idx = 0;
            foreach ($evs as $e) {
                $paso = $def['pasos'][$idx];
                if ($e['tipo'] === $paso['tipo'] && $e['pagina'] === $paso['pagina']
                    && (!isset($paso['elemento']) || $e['elemento'] === $paso['elemento'])) {
                    $ses[$idx]++;
                    if ($e['usuario_id'] !== null) $usr[$idx][$e['usuario_id']] = true;
                    $idx++;
                    if ($idx >= $n) break;
                }
            }
        }
        $pasos = [];
        for ($i = 0; $i < $n; $i++) {
            $pasos[] = [
                'etiqueta' => $def['pasos'][$i]['etiqueta'],
                'sesiones' => $ses[$i],
                'usuarios' => count($usr[$i]),
                'pct_inicio' => analyticsPct($ses[$i], $ses[0]),
                'pct_previo' => $i === 0 ? null : analyticsPct($ses[$i], $ses[$i - 1]),
            ];
        }
        $out[] = ['id' => $def['id'], 'nombre' => $def['nombre'], 'pasos' => $pasos];
    }
    return $out;
}

// ------------------------------------------------------------- usuarios

function analyticsUsuarios(PDO $pdo, array $get): array {
    $f = analyticsFiltroDesdeGet($get);
    $rows = analyticsSql($pdo, "SELECT usuario_id, MAX(usuario) AS usuario, MAX(usuario_nombre) AS nombre, MAX(rol) AS rol,
            COUNT(DISTINCT sesion_id) AS sesiones,
            SUM(CASE WHEN tipo = 'pageview' THEN 1 ELSE 0 END) AS visitas,
            SUM(CASE WHEN tipo IN ('click', 'tab') THEN 1 ELSE 0 END) AS clics,
            SUM(CASE WHEN tipo = 'permanencia' THEN COALESCE(valor, 0) ELSE 0 END) AS act_ms,
            SUM(CASE WHEN " . ANALYTICS_SQL_ES_ERROR . " THEN 1 ELSE 0 END) AS errores,
            SUM(CASE WHEN tipo IN ('rage_click', 'dead_click', 'validacion_fallida') THEN 1 ELSE 0 END) AS frustracion,
            MAX(creado_en) AS ultimo, MIN(creado_en) AS primero
        FROM analytics_eventos WHERE {$f['sql']} AND usuario_id IS NOT NULL
        GROUP BY usuario_id", $f['params']);

    $dias = [];
    foreach (analyticsSql($pdo, "SELECT usuario_id, DATE(creado_en) AS d FROM analytics_eventos
            WHERE {$f['sql']} AND tipo = 'pageview' AND usuario_id IS NOT NULL
            GROUP BY usuario_id, DATE(creado_en)", $f['params']) as $r) {
        $dias[$r['usuario_id']] = ($dias[$r['usuario_id']] ?? 0) + 1;
    }
    $favoritos = [];
    foreach (analyticsSql($pdo, "SELECT usuario_id, pagina, COUNT(*) AS n FROM analytics_eventos
            WHERE {$f['sql']} AND tipo = 'pageview' AND usuario_id IS NOT NULL
            GROUP BY usuario_id, pagina", $f['params']) as $r) {
        $mod = analyticsInfoPagina($r['pagina'])['modulo'];
        $favoritos[$r['usuario_id']][$mod] = ($favoritos[$r['usuario_id']][$mod] ?? 0) + (int)$r['n'];
    }

    $usuarios = [];
    $activosIds = [];
    foreach ($rows as $r) {
        $uid = (int)$r['usuario_id'];
        $activosIds[$uid] = true;
        $fav = $favoritos[$uid] ?? [];
        arsort($fav);
        $usuarios[] = [
            'usuario_id' => $uid, 'usuario' => $r['usuario'], 'nombre' => $r['nombre'], 'rol' => $r['rol'],
            'sesiones' => (int)$r['sesiones'], 'visitas' => (int)$r['visitas'], 'clics' => (int)$r['clics'],
            'act_min' => round(((int)$r['act_ms']) / 60000, 1),
            'dias_activos' => $dias[$uid] ?? 0,
            'errores' => (int)$r['errores'], 'frustracion' => (int)$r['frustracion'],
            'ultimo' => $r['ultimo'],
            'modulo_favorito' => !empty($fav) ? array_key_first($fav) : null,
        ];
    }
    usort($usuarios, fn($a, $b) => $b['act_min'] <=> $a['act_min']);

    $inactivos = [];
    try {
        $todos = analyticsSql($pdo, "SELECT id, usuario, nombre, rol FROM usuarios ORDER BY nombre", []);
        foreach ($todos as $u) {
            if (!isset($activosIds[(int)$u['id']]) && $u['rol'] !== ROL_ACCESO_TOTAL) {
                $inactivos[] = ['usuario_id' => (int)$u['id'], 'usuario' => $u['usuario'], 'nombre' => $u['nombre'], 'rol' => $u['rol']];
            }
        }
    } catch (\Throwable $e) {
        error_log('Analíticas: no se pudo leer la tabla usuarios: ' . $e->getMessage());
    }

    return ['usuarios' => $usuarios, 'inactivos' => $inactivos];
}

function analyticsUsuarioDetalle(PDO $pdo, array $get): array {
    $uid = (int)($get['usuario_id'] ?? 0);
    if ($uid <= 0) return ['error' => 'Falta el usuario.'];

    $get['incluir_programador'] = '1'; // al ver un usuario en particular no se excluye por rol
    $get['rol'] = '';
    $rango = analyticsRango($get);
    $f = analyticsFiltroDesdeGet($get, $rango);
    $visitas = analyticsVisitas($pdo, $f);
    $sesiones = analyticsSesiones($visitas);

    $info = analyticsSql($pdo, "SELECT MAX(usuario) AS usuario, MAX(usuario_nombre) AS nombre, MAX(rol) AS rol,
            MIN(creado_en) AS primero, MAX(creado_en) AS ultimo FROM analytics_eventos WHERE usuario_id = ?", [$uid])[0] ?? [];

    $mods = [];
    $dias = [];
    $horas = array_fill(0, 24, 0);
    foreach ($visitas as $v) {
        $m = analyticsInfoPagina($v['pagina'])['modulo'];
        if (!isset($mods[$m])) $mods[$m] = ['modulo' => $m, 'visitas' => 0, 'act_s' => 0, 'clics' => 0];
        $mods[$m]['visitas']++;
        $mods[$m]['act_s'] += $v['act_ms'] / 1000;
        $mods[$m]['clics'] += $v['clics'];
        $d = date('Y-m-d', $v['ts']);
        $dias[$d] = ($dias[$d] ?? 0) + 1;
        $horas[(int)date('G', $v['ts'])]++;
    }
    usort($mods, fn($a, $b) => $b['visitas'] <=> $a['visitas']);
    $mods = array_map(fn($m) => ['modulo' => $m['modulo'], 'visitas' => $m['visitas'], 'act_min' => round($m['act_s'] / 60, 1), 'clics' => $m['clics']], $mods);
    ksort($dias);

    usort($sesiones, fn($a, $b) => $b['ini'] <=> $a['ini']);
    $listaSes = [];
    foreach (array_slice($sesiones, 0, 40) as $s) {
        $seq = analyticsSecuenciaPaginas($s);
        $listaSes[] = [
            'sesion_id' => $s['sesion_id'], 'inicio' => date('Y-m-d H:i:s', $s['ini']),
            'duracion_s' => $s['duracion_s'], 'act_s' => round($s['act_ms'] / 1000),
            'paginas' => count($s['visitas']), 'clics' => $s['clics'], 'errores' => $s['errores'],
            'ruta' => array_map(fn($p) => analyticsInfoPagina($p)['nombre'], $seq),
        ];
    }

    $disp = analyticsDistribucion($pdo, $f, 'dispositivo');
    $nav = analyticsDistribucion($pdo, $f, 'navegador');
    $el = analyticsElementos($pdo, $get);
    $errores = analyticsSql($pdo, "SELECT creado_en, tipo, pagina, elemento, valor, extra FROM analytics_eventos
        WHERE {$f['sql']} AND " . ANALYTICS_SQL_ES_ERROR . " ORDER BY id DESC LIMIT 30", $f['params']);

    $actTotal = array_sum(array_column($visitas, 'act_ms'));
    return [
        'usuario' => ['usuario_id' => $uid] + array_map(fn($x) => $x, $info),
        'kpis' => [
            'sesiones' => count($sesiones), 'visitas' => count($visitas),
            'clics' => array_sum(array_column($visitas, 'clics')),
            'act_total_min' => round($actTotal / 60000, 1),
            'dias_activos' => count($dias),
            'act_prom_sesion_s' => count($sesiones) > 0 ? round(($actTotal / 1000) / count($sesiones)) : null,
            'paginas_por_sesion' => count($sesiones) > 0 ? round(count($visitas) / count($sesiones), 1) : null,
        ],
        'modulos' => $mods,
        'dias' => array_map(fn($d, $n) => ['fecha' => $d, 'visitas' => $n], array_keys($dias), $dias),
        'horas' => $horas,
        'sesiones' => $listaSes,
        'dispositivos' => $disp,
        'navegadores' => $nav,
        'elementos' => array_slice($el['top'], 0, 25),
        'frustracion' => $el['frustracion'],
        'errores' => $errores,
    ];
}

function analyticsSesionDetalle(PDO $pdo, string $sesionId): array {
    $sesionId = preg_replace('/[^A-Za-z0-9_-]/', '', $sesionId);
    $rows = analyticsSql($pdo, "SELECT id, creado_en, tipo, pagina, elemento, detalle, valor, extra, visita_id, usuario, usuario_nombre, rol, dispositivo, navegador, sistema
        FROM analytics_eventos WHERE sesion_id = ? ORDER BY id ASC LIMIT 3000", [$sesionId]);
    if (empty($rows)) return ['eventos' => []];
    $t0 = strtotime($rows[0]['creado_en']);
    $eventos = array_map(function ($r) use ($t0) {
        return [
            't' => (int)strtotime($r['creado_en']) - $t0, 'hora' => substr($r['creado_en'], 11, 8),
            'tipo' => $r['tipo'], 'pagina' => $r['pagina'], 'nombre_pagina' => analyticsInfoPagina($r['pagina'])['nombre'],
            'elemento' => $r['elemento'], 'detalle' => $r['detalle'], 'valor' => $r['valor'] !== null ? (int)$r['valor'] : null,
            'extra' => $r['extra'] !== null ? (int)$r['extra'] : null, 'visita_id' => $r['visita_id'],
        ];
    }, $rows);
    return [
        'usuario' => ['usuario' => $rows[0]['usuario'], 'nombre' => $rows[0]['usuario_nombre'], 'rol' => $rows[0]['rol']],
        'dispositivo' => $rows[0]['dispositivo'], 'navegador' => $rows[0]['navegador'], 'sistema' => $rows[0]['sistema'],
        'inicio' => $rows[0]['creado_en'], 'eventos' => $eventos,
    ];
}

// ------------------------------------------------------------- adopción

function analyticsAdopcion(PDO $pdo, array $get): array {
    $rango = analyticsRango($get);
    $f = analyticsFiltroDesdeGet($get, $rango);
    $visitas = analyticsVisitas($pdo, $f);
    $usuariosTotal = analyticsUsuariosUnicos($visitas);

    $porMod = [];
    $rolMod = [];
    foreach ($visitas as $v) {
        $m = analyticsInfoPagina($v['pagina'])['modulo'];
        if (!isset($porMod[$m])) $porMod[$m] = ['visitas' => 0, 'usuarios' => []];
        $porMod[$m]['visitas']++;
        if ($v['usuario_id'] !== null) $porMod[$m]['usuarios'][$v['usuario_id']] = true;
        $rol = $v['rol'] ?: 'Desconocido';
        $rolMod[$rol][$m] = ($rolMod[$rol][$m] ?? 0) + 1;
    }

    $modulos = [];
    foreach (analyticsModulosCatalogo() as $m) {
        $d = $porMod[$m] ?? ['visitas' => 0, 'usuarios' => []];
        $modulos[] = ['modulo' => $m, 'visitas' => $d['visitas'], 'usuarios' => count($d['usuarios']),
                      'adopcion_pct' => analyticsPct(count($d['usuarios']), $usuariosTotal)];
    }
    usort($modulos, fn($a, $b) => $b['usuarios'] <=> $a['usuarios'] ?: $b['visitas'] <=> $a['visitas']);

    // DAU / WAU / MAU al cierre del rango
    $activos = function (int $dias) use ($pdo, $get, $rango) {
        $desde = date('Y-m-d', strtotime($rango['hasta'] . ' -' . ($dias - 1) . ' days'));
        $ff = analyticsFiltroDesdeGet($get, ['desde' => $desde, 'hasta' => $rango['hasta']]);
        return (int)(analyticsSql($pdo, "SELECT COUNT(DISTINCT usuario_id) AS n FROM analytics_eventos
            WHERE {$ff['sql']} AND tipo = 'pageview'", $ff['params'])[0]['n'] ?? 0);
    };
    $dau = $activos(1);
    $wau = $activos(7);
    $mau = $activos(30);

    $porDia = [];
    foreach ($visitas as $v) {
        if ($v['usuario_id'] !== null) $porDia[date('Y-m-d', $v['ts'])][$v['usuario_id']] = true;
    }
    $dauProm = $rango['dias'] > 0 ? round(array_sum(array_map('count', $porDia)) / $rango['dias'], 1) : null;

    // Nuevos vs recurrentes por semana
    $primeros = [];
    $fSinFecha = analyticsFiltro(['desde' => '2000-01-01', 'hasta' => $rango['hasta'],
        'incluir_programador' => !empty($get['incluir_programador']) && $get['incluir_programador'] !== '0',
        'rol' => $get['rol'] ?? '']);
    foreach (analyticsSql($pdo, "SELECT usuario_id, MIN(creado_en) AS primero FROM analytics_eventos
            WHERE {$fSinFecha['sql']} AND tipo = 'pageview' AND usuario_id IS NOT NULL GROUP BY usuario_id", $fSinFecha['params']) as $r) {
        $primeros[(int)$r['usuario_id']] = strtotime($r['primero']);
    }
    $semanas = [];
    foreach ($visitas as $v) {
        if ($v['usuario_id'] === null) continue;
        $sem = date('o-\WW', $v['ts']);
        $semanas[$sem][$v['usuario_id']] = true;
    }
    ksort($semanas);
    $semanal = [];
    foreach ($semanas as $sem => $usrs) {
        $nuevos = 0;
        foreach ($usrs as $uid => $_) {
            if (isset($primeros[$uid]) && date('o-\WW', $primeros[$uid]) === $sem) $nuevos++;
        }
        $semanal[] = ['semana' => $sem, 'activos' => count($usrs), 'nuevos' => $nuevos, 'recurrentes' => count($usrs) - $nuevos];
    }

    $rolMatriz = [];
    foreach ($rolMod as $rol => $mods) {
        $rolMatriz[] = ['rol' => $rol, 'modulos' => $mods];
    }

    $u = analyticsUsuarios($pdo, $get);
    $hist = ['1 día' => 0, '2-3 días' => 0, '4-7 días' => 0, '8+ días' => 0];
    foreach ($u['usuarios'] as $x) {
        $d = $x['dias_activos'];
        if ($d <= 1) $hist['1 día']++;
        elseif ($d <= 3) $hist['2-3 días']++;
        elseif ($d <= 7) $hist['4-7 días']++;
        else $hist['8+ días']++;
    }

    return [
        'usuarios_activos' => $usuariosTotal,
        'modulos' => $modulos,
        'modulos_sin_uso' => array_values(array_map(fn($m) => $m['modulo'], array_filter($modulos, fn($m) => $m['visitas'] === 0))),
        'dau' => $dau, 'wau' => $wau, 'mau' => $mau, 'dau_promedio' => $dauProm,
        'stickiness_pct' => $mau > 0 && $dauProm !== null ? round(($dauProm / $mau) * 100, 1) : null,
        'semanal' => $semanal,
        'rol_modulo' => $rolMatriz,
        'frecuencia' => array_map(fn($k, $v) => ['rango' => $k, 'usuarios' => $v], array_keys($hist), $hist),
        'inactivos' => $u['inactivos'],
    ];
}

// ------------------------------------------------------------ hallazgos

function analyticsHallazgos(PDO $pdo, array $get): array {
    $resumen = analyticsResumen($pdo, $get);
    $mod = analyticsModulos($pdo, $get);
    $fri = analyticsFriccion($pdo, $get);
    $ado = analyticsAdopcion($pdo, $get);
    $el = analyticsElementos($pdo, $get);
    $k = $resumen['kpis'];

    $h = [];
    $add = function (string $nivel, string $area, string $titulo, string $detalle, string $sugerencia) use (&$h) {
        $h[] = ['nivel' => $nivel, 'area' => $area, 'titulo' => $titulo, 'detalle' => $detalle, 'sugerencia' => $sugerencia];
    };

    if ($k['visitas'] < 30) {
        $add('info', 'Datos', 'Aún hay pocos datos', "Solo hay {$k['visitas']} visitas registradas en el periodo. Las conclusiones mejoran con más uso.",
            'Deja correr la recolección unas semanas antes de tomar decisiones de diseño.');
    }

    // Módulos
    $sinInicio = array_values(array_filter($mod['modulos'], fn($m) => $m['modulo'] !== 'Inicio'));
    if (!empty($sinInicio)) {
        $top = $sinInicio[0];
        $add('info', 'Uso', "Módulo más usado: {$top['modulo']}", "Concentra {$top['pct_visitas']}% de las visitas ({$top['visitas']}) y {$top['usuarios']} usuarios distintos.",
            'Es el flujo a cuidar: cualquier mejora aquí impacta a la mayoría. Prioriza su rapidez y claridad.');
    }
    if (!empty($ado['modulos_sin_uso'])) {
        $add('medio', 'Adopción', 'Módulos sin ningún uso en el periodo', implode(', ', $ado['modulos_sin_uso']) . '.',
            'Confirma si son necesarios, si están escondidos en la navegación o si el equipo no sabe que existen (capacitación/menú).');
    }

    // Páginas con poco tiempo y mucha salida
    foreach ($mod['paginas'] as $p) {
        if ($p['visitas'] >= 10 && $p['tiempo_mediano_s'] !== null && $p['tiempo_mediano_s'] < 8 && ($p['tasa_salida_pct'] ?? 0) >= 60
            && !in_array($p['pagina'], ['index.html'], true)) {
            $add('medio', 'Diseño', "Se abandona rápido: {$p['nombre']}",
                "Mediana de {$p['tiempo_mediano_s']} s y {$p['tasa_salida_pct']}% de las visitas terminan ahí ({$p['visitas']} visitas).",
                'Revisa si la página no muestra lo que se busca de inmediato, si se llega por error o si la tarea se resuelve mal.');
        }
        if ($p['visitas'] >= 10 && $p['carga_p95_ms'] !== null && $p['carga_p95_ms'] > 3000) {
            $add('alto', 'Rendimiento', "Carga lenta: {$p['nombre']}", "El 5% de las cargas tarda más de " . round($p['carga_p95_ms'] / 1000, 1) . " s (mediana " . round(($p['carga_p50_ms'] ?? 0) / 1000, 1) . " s).",
                'Optimiza recursos de la página y las consultas que dispara al abrir.');
        }
        if ($p['visitas'] >= 10 && $p['errores'] > 0 && ($p['errores'] / $p['visitas']) >= 0.2) {
            $add('alto', 'Errores', "Errores frecuentes en {$p['nombre']}", "{$p['errores']} errores en {$p['visitas']} visitas.", 'Revisa la pestaña Fricción para ver el detalle de cada error.');
        }
    }

    // Endpoints
    foreach ($fri['endpoints'] as $e) {
        if ($e['llamadas'] >= 10 && ($e['error_pct'] ?? 0) >= 2) {
            $add('alto', 'Errores', "La API falla: {$e['endpoint']}", "{$e['error_pct']}% de {$e['llamadas']} llamadas terminaron en error.", 'Revisa el log de errores del servidor para ese endpoint.');
        } elseif ($e['llamadas'] >= 10 && $e['p95_ms'] > 3000) {
            $add('medio', 'Rendimiento', "Endpoint lento: {$e['endpoint']}", "p95 de " . round($e['p95_ms'] / 1000, 1) . " s (mediana " . round($e['p50_ms'] / 1000, 1) . " s) en {$e['llamadas']} llamadas.", 'Candidato a optimizar consultas o agregar índices.');
        }
    }
    if (!empty($fri['errores_js'])) {
        $t = $fri['errores_js'][0];
        $add('alto', 'Errores', count($fri['errores_js']) . ' errores de JavaScript distintos', "El más frecuente: \"{$t['mensaje']}\" en {$t['nombre_pagina']} ({$t['n']} veces, {$t['usuarios']} usuarios).", 'Corrígelo primero: el usuario probablemente vio algo roto.');
    }

    // Fricción
    foreach ($el['frustracion'] as $x) {
        if ($x['n'] >= 3) {
            $q = ['rage_click' => 'clics repetidos y furiosos', 'dead_click' => 'clics sobre algo que parece interactivo pero no responde', 'validacion_fallida' => 'validaciones de formulario fallidas'][$x['tipo']] ?? $x['tipo'];
            $add($x['tipo'] === 'validacion_fallida' ? 'medio' : 'alto', 'Diseño', "Fricción en \"{$x['elemento']}\" ({$x['nombre_pagina']})", "{$x['n']} {$q} ({$x['usuarios']} usuarios).",
                $x['tipo'] === 'validacion_fallida' ? 'El campo confunde: mejora la etiqueta, el formato esperado o el mensaje de error.' : 'Haz que responda (o que parezca no clicable) y confirma que la acción tiene retroalimentación visible.');
        }
    }

    // Pestañas poco usadas
    $porPagina = [];
    foreach ($mod['tabs'] as $t) $porPagina[$t['pagina']][] = $t;
    foreach ($porPagina as $pag => $tabs) {
        $total = array_sum(array_column($tabs, 'n'));
        if ($total < 30) continue;
        foreach ($tabs as $t) {
            if ($t['n'] / $total < 0.05) {
                $add('bajo', 'Diseño', "Sección poco usada: \"{$t['elemento']}\" en {$t['nombre_pagina']}", "Solo " . round(($t['n'] / $total) * 100, 1) . "% de los cambios de pestaña ({$t['n']} de {$total}).",
                    'Evalúa simplificarla, moverla o quitarla; o si es valiosa, hacerla más visible.');
            }
        }
    }

    // Dispositivos
    $totalDisp = array_sum(array_column($resumen['dispositivos'], 'sesiones'));
    foreach ($resumen['dispositivos'] as $d) {
        if ($d['nombre'] !== 'escritorio' && $totalDisp > 0 && ($d['sesiones'] / $totalDisp) >= 0.2) {
            $add('medio', 'Diseño', 'Uso relevante desde ' . $d['nombre'], round(($d['sesiones'] / $totalDisp) * 100) . "% de las sesiones ({$d['sesiones']}).", 'Prioriza que los flujos principales funcionen bien en pantalla chica y táctil.');
        }
    }

    // Horario pico
    $horas = array_fill(0, 24, 0);
    foreach ($resumen['heatmap'] as $fila) foreach ($fila as $hr => $n) $horas[$hr] += $n;
    if (array_sum($horas) >= 30) {
        arsort($horas);
        $pico = array_key_first($horas);
        $add('info', 'Uso', 'Hora de mayor actividad', sprintf('Entre las %02d:00 y las %02d:59 se concentra el mayor uso.', $pico, $pico), 'Evita desplegar cambios o migraciones en ese horario.');
    }

    // Usuarios
    if (!empty($ado['inactivos'])) {
        $nombres = array_map(fn($u) => $u['nombre'] ?: $u['usuario'], array_slice($ado['inactivos'], 0, 8));
        $add('medio', 'Adopción', count($ado['inactivos']) . ' usuarios sin actividad en el periodo', implode(', ', $nombres) . (count($ado['inactivos']) > 8 ? '…' : '') . '.',
            'Averigua si ya no usan el sistema, si tienen problemas de acceso o si necesitan capacitación.');
    }
    if ($ado['stickiness_pct'] !== null) {
        $add('info', 'Adopción', "Frecuencia de uso: {$ado['stickiness_pct']}%", "Usuarios activos por día promedio: {$ado['dau_promedio']}; activos en 30 días: {$ado['mau']}.",
            'Cerca de 100% = uso diario constante; valores bajos = uso ocasional.');
    }
    if ($k['rebote_pct'] !== null && $k['sesiones'] >= 20 && $k['rebote_pct'] >= 40) {
        $add('medio', 'Diseño', "Sesiones que rebotan: {$k['rebote_pct']}%", 'Sesiones de una sola página, con menos de 10 s de actividad y sin clics.', 'Revisa a dónde llegan esos usuarios y por qué no continúan.');
    }

    $orden = ['alto' => 0, 'medio' => 1, 'bajo' => 2, 'info' => 3];
    usort($h, fn($a, $b) => $orden[$a['nivel']] <=> $orden[$b['nivel']]);
    return ['hallazgos' => $h];
}
