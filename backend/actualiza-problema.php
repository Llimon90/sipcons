<?php
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../auth/permisos.php';
require_once __DIR__ . '/../auth/audit.php';
requirePermiso('problemas');
header('Content-Type: application/json');

const ESTATUS_VALIDOS_PROBLEMA = ['Abierto', 'En análisis', 'Resuelto', 'Cerrado'];
const PRIORIDADES_VALIDAS_PROBLEMA = ['Baja', 'Media', 'Alta', 'Crítica'];

try {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $id = (int)($data['id'] ?? 0);
    if ($id <= 0) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Falta el id del problema.']);
        exit;
    }

    $pdo->beginTransaction();

    $stmt = $pdo->prepare('SELECT * FROM problemas WHERE id = ?');
    $stmt->execute([$id]);
    $anterior = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$anterior) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'El problema no existe.']);
        exit;
    }

    $titulo = trim((string)($data['titulo'] ?? $anterior['titulo']));
    $descripcion = array_key_exists('descripcion', $data) ? trim((string)$data['descripcion']) : $anterior['descripcion'];
    $causaRaiz = array_key_exists('causa_raiz', $data) ? trim((string)$data['causa_raiz']) : $anterior['causa_raiz'];
    $prioridad = in_array($data['prioridad'] ?? '', PRIORIDADES_VALIDAS_PROBLEMA, true) ? $data['prioridad'] : $anterior['prioridad'];
    $estatus = in_array($data['estatus'] ?? '', ESTATUS_VALIDOS_PROBLEMA, true) ? $data['estatus'] : $anterior['estatus'];
    $fechaResolucion = $anterior['fecha_resolucion'];
    if ($estatus === 'Resuelto' || $estatus === 'Cerrado') {
        $fechaResolucion = !empty($data['fecha_resolucion']) ? (string)$data['fecha_resolucion'] : ($fechaResolucion ?? date('Y-m-d'));
    }

    $pdo->prepare(
        'UPDATE problemas SET titulo = ?, descripcion = ?, causa_raiz = ?, prioridad = ?, estatus = ?, fecha_resolucion = ? WHERE id = ?'
    )->execute([$titulo, $descripcion ?: null, $causaRaiz ?: null, $prioridad, $estatus, $fechaResolucion, $id]);

    $pdo->commit();

    registrarAuditoria('problemas', $id, 'UPDATE', $anterior, [
        'titulo' => $titulo, 'estatus' => $estatus, 'prioridad' => $prioridad,
    ], $anterior['folio']);

    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error al actualizar el problema.']);
}
