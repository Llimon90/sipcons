<?php
require_once __DIR__ . '/../auth/middleware.php';
requireRole('Administrador', 'Programador');
require_once __DIR__ . '/../auth/audit.php';
require_once __DIR__ . '/lib/automatizacion_helpers.php';

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

    $stmtAntes = $pdo->query("SELECT id, nombre, campo_condicion, valor_condicion, accion, valor_accion, activo, orden FROM reglas_automatizacion");
    $antes = $stmtAntes->fetchAll(PDO::FETCH_ASSOC);

    $pdo->beginTransaction();

    // Reemplazo completo (igual que sla_reglas): se borran las que ya no
    // vengan en la lista y se insertan/actualizan las demás por id.
    $idsEnviados = array_values(array_filter(array_map(fn($r) => (int)($r['id'] ?? 0), $reglas)));
    if ($idsEnviados) {
        $marcadores = implode(',', array_fill(0, count($idsEnviados), '?'));
        $pdo->prepare("DELETE FROM reglas_automatizacion WHERE id NOT IN ($marcadores)")->execute($idsEnviados);
    } else {
        $pdo->exec("DELETE FROM reglas_automatizacion");
    }

    $insert = $pdo->prepare(
        "INSERT INTO reglas_automatizacion (nombre, campo_condicion, valor_condicion, accion, valor_accion, activo, orden)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    );
    $update = $pdo->prepare(
        "UPDATE reglas_automatizacion SET nombre = ?, campo_condicion = ?, valor_condicion = ?, accion = ?, valor_accion = ?, activo = ?, orden = ? WHERE id = ?"
    );

    $guardadas = [];
    foreach ($reglas as $i => $r) {
        $campoCondicion = $r['campo_condicion'] ?? null;
        $accion = $r['accion'] ?? null;
        if (!campoCondicionValido($campoCondicion) || !accionAutomatizacionValida($accion)) continue;

        $nombre = mb_substr(trim((string)($r['nombre'] ?? '')), 0, 150);
        $valorCondicion = trim((string)($r['valor_condicion'] ?? ''));
        $valorAccion = trim((string)($r['valor_accion'] ?? ''));
        if ($nombre === '' || $valorCondicion === '' || $valorAccion === '') continue;
        if ($accion === 'cambiar_prioridad' && !prioridadValida($valorAccion)) continue;

        $activo = !empty($r['activo']) ? 1 : 0;
        $orden = (int)($r['orden'] ?? $i);
        $id = (int)($r['id'] ?? 0);

        if ($id > 0) {
            $update->execute([$nombre, $campoCondicion, $valorCondicion, $accion, $valorAccion, $activo, $orden, $id]);
        } else {
            $insert->execute([$nombre, $campoCondicion, $valorCondicion, $accion, $valorAccion, $activo, $orden]);
        }
        $guardadas[] = compact('nombre', 'campoCondicion', 'valorCondicion', 'accion', 'valorAccion', 'activo', 'orden');
    }

    $pdo->commit();
    registrarAuditoria('reglas_automatizacion', null, 'UPDATE', $antes, $guardadas);

    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error al guardar las reglas de automatización: ' . $e->getMessage()]);
}
