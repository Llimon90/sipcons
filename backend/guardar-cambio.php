<?php
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../auth/permisos.php';
require_once __DIR__ . '/../auth/audit.php';
requirePermiso('cambios');
header('Content-Type: application/json');

const TIPOS_CAMBIO_VALIDOS = ['Estándar', 'Normal', 'Emergencia'];
const RIESGOS_VALIDOS = ['Bajo', 'Medio', 'Alto'];

try {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];

    $titulo = trim((string)($data['titulo'] ?? ''));
    $descripcion = trim((string)($data['descripcion'] ?? ''));
    $tipo = in_array($data['tipo'] ?? '', TIPOS_CAMBIO_VALIDOS, true) ? $data['tipo'] : 'Normal';
    $riesgo = in_array($data['riesgo'] ?? '', RIESGOS_VALIDOS, true) ? $data['riesgo'] : 'Medio';
    $fechaPlaneada = !empty($data['fecha_planeada']) ? (string)$data['fecha_planeada'] : null;
    $notas = trim((string)($data['notas'] ?? ''));

    if ($titulo === '') {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'El cambio necesita un título.']);
        exit;
    }

    $solicitante = $_SESSION['nombre'] ?? ($_SESSION['usuario'] ?? 'Desconocido');

    $pdo->beginTransaction();

    // Folio: mismo estilo que problemas/cotizaciones (CAM-00001).
    $ultimo = $pdo->query("SELECT folio FROM cambios ORDER BY id DESC LIMIT 1")->fetchColumn();
    $folio = 'CAM-' . str_pad($ultimo ? (int)substr($ultimo, 4) + 1 : 1, 5, '0', STR_PAD_LEFT);

    $pdo->prepare(
        'INSERT INTO cambios (folio, titulo, descripcion, tipo, riesgo, fecha_planeada, solicitante, notas, creado_por)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $folio, $titulo, $descripcion !== '' ? $descripcion : null, $tipo, $riesgo,
        $fechaPlaneada, $solicitante, $notas !== '' ? $notas : null, $solicitante,
    ]);
    $cambioId = (int)$pdo->lastInsertId();

    $pdo->commit();

    registrarAuditoria('cambios', $cambioId, 'CREATE', null, [
        'titulo' => $titulo, 'tipo' => $tipo, 'riesgo' => $riesgo,
    ], $folio);

    echo json_encode(['success' => true, 'id' => $cambioId, 'folio' => $folio]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error al guardar el cambio.']);
}
