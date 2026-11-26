<?php
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../auth/permisos.php';
require_once __DIR__ . '/../auth/audit.php';
requirePermiso('cambios');
header('Content-Type: application/json');

const TIPOS_CAMBIO_VALIDOS_EDIT = ['Estándar', 'Normal', 'Emergencia'];
const RIESGOS_VALIDOS_EDIT = ['Bajo', 'Medio', 'Alto'];
// La aprobación/rechazo NO se hace aquí: ver backend/aprobar-cambio.php.
// Este endpoint solo deja mover 'Aprobado' -> 'Implementado' -> 'Cerrado'.
const ESTATUS_EDITABLES_AQUI = ['Implementado', 'Cerrado'];

try {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $id = (int)($data['id'] ?? 0);
    if ($id <= 0) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Falta el id del cambio.']);
        exit;
    }

    $pdo->beginTransaction();

    $stmt = $pdo->prepare('SELECT * FROM cambios WHERE id = ?');
    $stmt->execute([$id]);
    $anterior = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$anterior) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'El cambio no existe.']);
        exit;
    }

    $titulo = trim((string)($data['titulo'] ?? $anterior['titulo']));
    $descripcion = array_key_exists('descripcion', $data) ? trim((string)$data['descripcion']) : $anterior['descripcion'];
    $tipo = in_array($data['tipo'] ?? '', TIPOS_CAMBIO_VALIDOS_EDIT, true) ? $data['tipo'] : $anterior['tipo'];
    $riesgo = in_array($data['riesgo'] ?? '', RIESGOS_VALIDOS_EDIT, true) ? $data['riesgo'] : $anterior['riesgo'];
    $fechaPlaneada = array_key_exists('fecha_planeada', $data) ? ($data['fecha_planeada'] ?: null) : $anterior['fecha_planeada'];
    $notas = array_key_exists('notas', $data) ? trim((string)$data['notas']) : $anterior['notas'];

    $estatus = $anterior['estatus'];
    if (isset($data['estatus']) && in_array($data['estatus'], ESTATUS_EDITABLES_AQUI, true)) {
        if ($anterior['estatus'] !== 'Aprobado' && $data['estatus'] === 'Implementado') {
            $pdo->rollBack();
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Solo un cambio Aprobado puede marcarse como Implementado.']);
            exit;
        }
        $estatus = $data['estatus'];
    }

    $pdo->prepare(
        'UPDATE cambios SET titulo = ?, descripcion = ?, tipo = ?, riesgo = ?, fecha_planeada = ?, notas = ?, estatus = ? WHERE id = ?'
    )->execute([$titulo, $descripcion ?: null, $tipo, $riesgo, $fechaPlaneada, $notas ?: null, $estatus, $id]);

    $pdo->commit();

    registrarAuditoria('cambios', $id, 'UPDATE', $anterior, [
        'titulo' => $titulo, 'estatus' => $estatus,
    ], $anterior['folio']);

    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error al actualizar el cambio.']);
}
