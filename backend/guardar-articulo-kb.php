<?php
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../auth/permisos.php';
require_once __DIR__ . '/../auth/audit.php';
requirePermiso('base_conocimiento');
header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true) ?: [];
$titulo = trim((string)($data['titulo'] ?? ''));
$contenido = trim((string)($data['contenido'] ?? ''));
$categoria = trim((string)($data['categoria'] ?? ''));
$visibilidad = ($data['visibilidad'] ?? '') === 'Publico' ? 'Publico' : 'Interno';

if ($titulo === '' || $contenido === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'El título y el contenido son obligatorios.']);
    exit;
}

try {
    $stmt = $pdo->prepare(
        'INSERT INTO kb_articulos (titulo, contenido, categoria, visibilidad, autor_id, autor_nombre)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $titulo, $contenido, $categoria !== '' ? $categoria : null, $visibilidad,
        $_SESSION['user_id'] ?? null, $_SESSION['nombre'] ?? ($_SESSION['usuario'] ?? null),
    ]);
    $id = (int)$pdo->lastInsertId();

    registrarAuditoria('kb_articulos', $id, 'CREATE', null, compact('titulo', 'categoria', 'visibilidad'));

    echo json_encode(['success' => true, 'id' => $id]);
} catch (Throwable $e) {
    error_log('guardar-articulo-kb.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'No se pudo guardar el artículo.']);
}
