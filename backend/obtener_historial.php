<?php
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../auth/permisos.php';
requirePermiso('historial');
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    $porPagina = 50;
    $pagina    = max(1, (int)($_GET['pagina'] ?? 1));
    $offset    = ($pagina - 1) * $porPagina;

    $tabla      = trim($_GET['tabla'] ?? '');
    $accion     = trim($_GET['accion'] ?? '');
    $registroId = trim($_GET['registro_id'] ?? '');
    $desde      = trim($_GET['desde'] ?? '');
    $hasta      = trim($_GET['hasta'] ?? '');

    $condiciones = [];
    $params = [];

    if ($tabla !== '') {
        $condiciones[] = 'tabla = ?';
        $params[] = $tabla;
    }
    if ($accion !== '') {
        $condiciones[] = 'accion = ?';
        $params[] = $accion;
    }
    if ($registroId !== '') {
        // Acepta tanto el id numérico interno como el folio/número visible (VT-00005, SIP-0001)
        $condiciones[] = '(registro_id = ? OR registro_folio = ?)';
        $params[] = $registroId;
        $params[] = $registroId;
    }
    if ($desde !== '') {
        $condiciones[] = 'creado_en >= ?';
        $params[] = $desde . ' 00:00:00';
    }
    if ($hasta !== '') {
        $condiciones[] = 'creado_en <= ?';
        $params[] = $hasta . ' 23:59:59';
    }

    $where = $condiciones ? ('WHERE ' . implode(' AND ', $condiciones)) : '';

    $stmtTotal = $pdo->prepare("SELECT COUNT(*) AS total FROM auditoria $where");
    $stmtTotal->execute($params);
    $total = (int)$stmtTotal->fetch(PDO::FETCH_ASSOC)['total'];

    // $porPagina/$offset son enteros fijados por el propio servidor (no vienen del usuario sin castear), seguros de interpolar
    $stmt = $pdo->prepare(
        "SELECT id, usuario_id, usuario_nombre, usuario_rol, accion, tabla, registro_id, registro_folio, datos_anteriores, datos_nuevos, ip_address, creado_en
         FROM auditoria $where
         ORDER BY creado_en DESC, id DESC
         LIMIT $porPagina OFFSET $offset"
    );
    $stmt->execute($params);
    $registros = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($registros as &$registro) {
        $registro['datos_anteriores'] = $registro['datos_anteriores'] !== null ? json_decode($registro['datos_anteriores'], true) : null;
        $registro['datos_nuevos']     = $registro['datos_nuevos'] !== null ? json_decode($registro['datos_nuevos'], true) : null;
    }
    unset($registro);

    echo json_encode([
        'success'       => true,
        'data'          => $registros,
        'total'         => $total,
        'pagina'        => $pagina,
        'por_pagina'    => $porPagina,
        'total_paginas' => (int)ceil($total / $porPagina),
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error al obtener historial: ' . $e->getMessage()]);
}
