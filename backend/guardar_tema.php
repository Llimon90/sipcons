<?php
// Guarda el tema (claro/oscuro) del usuario en sesión. Cualquier rol puede
// cambiar el suyo; siempre se usa el id de la sesión, nunca uno enviado por
// el cliente, para que nadie pueda cambiar el tema de otra cuenta.
require_once __DIR__ . '/../auth/middleware.php';
header('Content-Type: application/json');

const TEMAS_VALIDOS = ['claro', 'oscuro'];

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    die(json_encode(['success' => false, 'message' => 'Método no permitido']));
}

$data = json_decode(file_get_contents('php://input'), true);
$tema = is_array($data) ? ($data['tema'] ?? '') : '';
if (!in_array($tema, TEMAS_VALIDOS, true)) {
    http_response_code(400);
    die(json_encode(['success' => false, 'message' => 'Tema no válido']));
}

$userId = $_SESSION['user_id'] ?? null;
if (!$userId) {
    http_response_code(401);
    die(json_encode(['success' => false, 'message' => 'Sesión no válida']));
}

try {
    $stmt = $pdo->prepare('UPDATE usuarios SET tema = ? WHERE id = ?');
    $stmt->execute([$tema, $userId]);
    echo json_encode(['success' => true, 'tema' => $tema]);
} catch (\Throwable $e) {
    error_log('No se pudo guardar el tema (¿falta la migración 012?): ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'No se pudo guardar el tema en tu cuenta. Se aplicó solo en este dispositivo.']);
}
