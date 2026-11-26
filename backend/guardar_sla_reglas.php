<?php
require_once __DIR__ . '/../auth/middleware.php';
requireRole('Administrador', 'Programador');
require_once __DIR__ . '/../auth/audit.php';
require_once __DIR__ . '/lib/sla_helpers.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    $data = json_decode(file_get_contents('php://input'), true);
    $reglas = $data['reglas'] ?? null;
    if (!is_array($reglas)) {
        http_response_code(400);
        die(json_encode(['success' => false, 'message' => 'Formato de datos inválido']));
    }

    $stmtAntes = $pdo->query("SELECT id, categoria, prioridad, horas_respuesta, horas_cierre, activo FROM sla_reglas");
    $antes = $stmtAntes->fetchAll(PDO::FETCH_ASSOC);

    $pdo->beginTransaction();
    $upsert = $pdo->prepare(
        "INSERT INTO sla_reglas (categoria, prioridad, horas_respuesta, horas_cierre, activo) VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE horas_respuesta = VALUES(horas_respuesta), horas_cierre = VALUES(horas_cierre), activo = VALUES(activo)"
    );

    $guardadas = [];
    foreach ($reglas as $r) {
        $prioridad = $r['prioridad'] ?? null;
        if (!prioridadValida($prioridad)) continue; // whitelist: ignora cualquier valor que no sea una de las 4 prioridades

        $categoria = trim((string)($r['categoria'] ?? ''));
        $categoria = $categoria === '' ? null : mb_substr($categoria, 0, 100);

        $horasRespuesta = max(0, (int)($r['horas_respuesta'] ?? 0));
        $horasCierre = max(0, (int)($r['horas_cierre'] ?? 0));
        if ($horasRespuesta === 0 || $horasCierre === 0) continue; // 0 horas no es una regla válida

        $activo = !empty($r['activo']) ? 1 : 0;

        $upsert->execute([$categoria, $prioridad, $horasRespuesta, $horasCierre, $activo]);
        $guardadas[] = compact('categoria', 'prioridad', 'horasRespuesta', 'horasCierre', 'activo');
    }

    $pdo->commit();
    registrarAuditoria('sla_reglas', null, 'UPDATE', $antes, $guardadas);

    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error al guardar las reglas de SLA: ' . $e->getMessage()]);
}
