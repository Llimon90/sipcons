<?php
// Encuesta CSAT pública, por token: NO requiere sesión (como una encuesta
// real por correo). Valida el token estrictamente y nunca expone datos de
// la incidencia más allá de lo mínimo (folio y categoría) ni el token de
// otra encuesta.
require_once __DIR__ . '/../config/database.php';
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

function tokenValido($v): bool {
    return is_string($v) && preg_match('/^[a-f0-9]{64}$/', $v) === 1;
}

$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$token = $metodo === 'POST'
    ? (string)((json_decode(file_get_contents('php://input'), true) ?: [])['token'] ?? '')
    : (string)($_GET['token'] ?? '');

if (!tokenValido($token)) {
    http_response_code(404);
    die(json_encode(['success' => false, 'message' => 'Encuesta no encontrada']));
}

$stmt = $pdo->prepare(
    'SELECT e.id, e.calificacion, e.respondido_en, i.numero_incidente, i.categoria
     FROM encuestas_csat e JOIN incidencias i ON i.id = e.incidencia_id
     WHERE e.token = ? LIMIT 1'
);
$stmt->execute([$token]);
$encuesta = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$encuesta) {
    http_response_code(404);
    die(json_encode(['success' => false, 'message' => 'Encuesta no encontrada']));
}

if ($metodo === 'GET') {
    echo json_encode([
        'success' => true,
        'numero_incidente' => $encuesta['numero_incidente'],
        'categoria' => $encuesta['categoria'],
        'ya_respondida' => $encuesta['respondido_en'] !== null,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($encuesta['respondido_en'] !== null) {
    http_response_code(409);
    die(json_encode(['success' => false, 'message' => 'Esta encuesta ya fue respondida.']));
}

$data = json_decode(file_get_contents('php://input'), true) ?: [];
$calificacion = (int)($data['calificacion'] ?? 0);
$comentario = trim((string)($data['comentario'] ?? ''));

if ($calificacion < 1 || $calificacion > 5) {
    http_response_code(422);
    die(json_encode(['success' => false, 'message' => 'Elige una calificación de 1 a 5.']));
}

$pdo->prepare('UPDATE encuestas_csat SET calificacion = ?, comentario = ?, respondido_en = NOW() WHERE id = ?')
    ->execute([$calificacion, $comentario !== '' ? $comentario : null, $encuesta['id']]);

echo json_encode(['success' => true, 'message' => 'Gracias por tu respuesta.']);
