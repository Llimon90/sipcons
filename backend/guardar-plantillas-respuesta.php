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

try {
    $data = json_decode(file_get_contents('php://input'), true);
    $plantillas = $data['plantillas'] ?? null;
    if (!is_array($plantillas)) {
        http_response_code(400);
        die(json_encode(['success' => false, 'message' => 'Formato de datos inválido']));
    }

    $stmtAntes = $pdo->query("SELECT id, titulo, contenido, categoria, activo FROM plantillas_respuesta");
    $antes = $stmtAntes->fetchAll(PDO::FETCH_ASSOC);

    $pdo->beginTransaction();

    $idsEnviados = array_values(array_filter(array_map(fn($p) => (int)($p['id'] ?? 0), $plantillas)));
    if ($idsEnviados) {
        $marcadores = implode(',', array_fill(0, count($idsEnviados), '?'));
        $pdo->prepare("DELETE FROM plantillas_respuesta WHERE id NOT IN ($marcadores)")->execute($idsEnviados);
    } else {
        $pdo->exec("DELETE FROM plantillas_respuesta");
    }

    $insert = $pdo->prepare("INSERT INTO plantillas_respuesta (titulo, contenido, categoria, creado_por, activo) VALUES (?, ?, ?, ?, ?)");
    $update = $pdo->prepare("UPDATE plantillas_respuesta SET titulo = ?, contenido = ?, categoria = ?, activo = ? WHERE id = ?");

    $guardadas = [];
    foreach ($plantillas as $p) {
        $titulo = mb_substr(trim((string)($p['titulo'] ?? '')), 0, 150);
        $contenido = trim((string)($p['contenido'] ?? ''));
        if ($titulo === '' || $contenido === '') continue;

        $categoria = trim((string)($p['categoria'] ?? ''));
        $categoria = $categoria === '' ? null : mb_substr($categoria, 0, 100);
        $activo = !empty($p['activo']) ? 1 : 0;
        $id = (int)($p['id'] ?? 0);

        if ($id > 0) {
            $update->execute([$titulo, $contenido, $categoria, $activo, $id]);
        } else {
            $insert->execute([$titulo, $contenido, $categoria, $_SESSION['nombre'] ?? null, $activo]);
        }
        $guardadas[] = compact('titulo', 'categoria', 'activo');
    }

    $pdo->commit();
    registrarAuditoria('plantillas_respuesta', null, 'UPDATE', $antes, $guardadas);

    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error al guardar las plantillas: ' . $e->getMessage()]);
}
