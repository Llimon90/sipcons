<?php
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../auth/audit.php';
header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true) ?: [];
$incidenciaId = (int)($data['incidencia_id'] ?? 0);
$comentario = trim((string)($data['comentario'] ?? ''));
$visibleCliente = isset($data['visible_cliente']) ? (int)(bool)$data['visible_cliente'] : 0;

if ($incidenciaId <= 0 || $comentario === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Escribe un comentario.']);
    exit;
}

try {
    $existe = $pdo->prepare('SELECT id, numero_incidente FROM incidencias WHERE id = ?');
    $existe->execute([$incidenciaId]);
    $incidencia = $existe->fetch(PDO::FETCH_ASSOC);
    if (!$incidencia) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'La incidencia no existe.']);
        exit;
    }

    $autorNombre = $_SESSION['nombre'] ?? ($_SESSION['usuario'] ?? 'Usuario');
    $stmt = $pdo->prepare(
        'INSERT INTO incidencia_comentarios (incidencia_id, autor_id, autor_nombre, comentario, visible_cliente) VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([$incidenciaId, $_SESSION['user_id'] ?? null, $autorNombre, $comentario, $visibleCliente]);
    $id = (int)$pdo->lastInsertId();

    registrarAuditoria('incidencia_comentarios', $id, 'CREATE', null, [
        'incidencia_id' => $incidenciaId, 'visible_cliente' => $visibleCliente,
    ], $incidencia['numero_incidente'] ?? null);

    echo json_encode(['success' => true, 'id' => $id, 'autor_nombre' => $autorNombre, 'creado_en' => date('Y-m-d H:i:s')]);
} catch (Throwable $e) {
    error_log('guardar-comentario-incidencia.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'No se pudo guardar el comentario.']);
}
