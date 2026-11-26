<?php
require_once __DIR__ . '/../auth/middleware.php';
requireRole('Administrador', 'Programador');
require_once __DIR__ . '/../auth/audit.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$COLOR_HEX = '/^#[0-9a-fA-F]{6}$/';

try {
    $data = json_decode(file_get_contents('php://input'), true);
    $etiquetas = $data['etiquetas'] ?? null;
    if (!is_array($etiquetas)) {
        http_response_code(400);
        die(json_encode(['success' => false, 'message' => 'Formato de datos inválido']));
    }

    $stmtAntes = $pdo->query("SELECT id, nombre, color FROM etiquetas");
    $antes = $stmtAntes->fetchAll(PDO::FETCH_ASSOC);

    $pdo->beginTransaction();

    $idsEnviados = array_values(array_filter(array_map(fn($e) => (int)($e['id'] ?? 0), $etiquetas)));
    if ($idsEnviados) {
        $marcadores = implode(',', array_fill(0, count($idsEnviados), '?'));
        // incidencia_etiquetas se limpia solo (ON DELETE CASCADE, migración 062)
        $pdo->prepare("DELETE FROM etiquetas WHERE id NOT IN ($marcadores)")->execute($idsEnviados);
    } else {
        $pdo->exec("DELETE FROM etiquetas");
    }

    $insert = $pdo->prepare("INSERT INTO etiquetas (nombre, color) VALUES (?, ?) ON DUPLICATE KEY UPDATE color = VALUES(color)");
    $update = $pdo->prepare("UPDATE etiquetas SET nombre = ?, color = ? WHERE id = ?");

    $guardadas = [];
    foreach ($etiquetas as $e) {
        $nombre = mb_substr(trim((string)($e['nombre'] ?? '')), 0, 60);
        if ($nombre === '') continue;
        $color = trim((string)($e['color'] ?? '#888888'));
        if (!preg_match($COLOR_HEX, $color)) $color = '#888888';
        $id = (int)($e['id'] ?? 0);

        if ($id > 0) {
            $update->execute([$nombre, $color, $id]);
        } else {
            $insert->execute([$nombre, $color]);
        }
        $guardadas[] = compact('nombre', 'color');
    }

    $pdo->commit();
    registrarAuditoria('etiquetas', null, 'UPDATE', $antes, $guardadas);

    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error al guardar etiquetas: ' . $e->getMessage()]);
}
