<?php
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../auth/permisos.php';
require_once __DIR__ . '/../auth/audit.php';
requirePermiso('problemas');
header('Content-Type: application/json');

const PRIORIDADES_VALIDAS = ['Baja', 'Media', 'Alta', 'Crítica'];

try {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];

    $titulo = trim((string)($data['titulo'] ?? ''));
    $descripcion = trim((string)($data['descripcion'] ?? ''));
    $causaRaiz = trim((string)($data['causa_raiz'] ?? ''));
    $prioridad = in_array($data['prioridad'] ?? '', PRIORIDADES_VALIDAS, true) ? $data['prioridad'] : 'Media';
    $fechaDeteccion = !empty($data['fecha_deteccion']) ? (string)$data['fecha_deteccion'] : date('Y-m-d');

    if ($titulo === '') {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'El problema necesita un título.']);
        exit;
    }

    $pdo->beginTransaction();

    // Folio: mismo estilo que cotizaciones/ventas (PRB-00001).
    $ultimo = $pdo->query("SELECT folio FROM problemas ORDER BY id DESC LIMIT 1")->fetchColumn();
    $folio = 'PRB-' . str_pad($ultimo ? (int)substr($ultimo, 4) + 1 : 1, 5, '0', STR_PAD_LEFT);

    $pdo->prepare(
        'INSERT INTO problemas (folio, titulo, descripcion, causa_raiz, prioridad, fecha_deteccion, creado_por)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $folio, $titulo, $descripcion !== '' ? $descripcion : null, $causaRaiz !== '' ? $causaRaiz : null,
        $prioridad, $fechaDeteccion, $_SESSION['nombre'] ?? ($_SESSION['usuario'] ?? null),
    ]);
    $problemaId = (int)$pdo->lastInsertId();

    $pdo->commit();

    registrarAuditoria('problemas', $problemaId, 'CREATE', null, [
        'titulo' => $titulo, 'prioridad' => $prioridad,
    ], $folio);

    echo json_encode(['success' => true, 'id' => $problemaId, 'folio' => $folio]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error al guardar el problema.']);
}
