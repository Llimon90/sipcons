<?php
// Datos de una incidencia para detalle.html. Incluye "version" (huella del
// registro) para que actualiza.php detecte si alguien la modificó mientras
// la página estaba abierta, y los límites de subida del servidor.
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/lib/incidencias_helpers.php';

ini_set('display_errors', '0');

$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$id) {
    http_response_code(400);
    echo json_encode(['error' => 'ID de incidencia inválido']);
    exit;
}

try {
    $st = $pdo->prepare('SELECT * FROM incidencias WHERE id = ?');
    $st->execute([$id]);
    $fila = $st->fetch(PDO::FETCH_ASSOC);

    if (!$fila) {
        http_response_code(404);
        echo json_encode(['error' => 'La incidencia no existe o fue eliminada']);
        exit;
    }

    $datos = incidenciaParaFrontend($pdo, $fila);
    $datos['limites_subida'] = limitesSubida();
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('detalle.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'No se pudo cargar la incidencia']);
}
