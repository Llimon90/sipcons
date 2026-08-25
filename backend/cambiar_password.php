<?php
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../auth/audit.php';
header('Content-Type: application/json');

try {
    $data = json_decode(file_get_contents('php://input'), true);
    $actual = $data['password_actual'] ?? '';
    $nueva  = $data['password_nueva'] ?? '';

    if (empty($actual) || empty($nueva)) {
        http_response_code(400);
        die(json_encode(['success' => false, 'message' => 'Completa la contraseña actual y la nueva']));
    }

    if (strlen($nueva) < 6) {
        http_response_code(400);
        die(json_encode(['success' => false, 'message' => 'La nueva contraseña debe tener al menos 6 caracteres']));
    }

    // Siempre el usuario en sesión: nunca un id que venga del cliente, para
    // que nadie pueda cambiar la contraseña de otra cuenta por esta vía.
    $userId = $_SESSION['user_id'] ?? null;
    if (!$userId) {
        http_response_code(401);
        die(json_encode(['success' => false, 'message' => 'Sesión no válida']));
    }

    $stmt = $pdo->prepare("SELECT password FROM usuarios WHERE id = ?");
    $stmt->execute([$userId]);
    $fila = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$fila || !password_verify($actual, $fila['password'])) {
        http_response_code(401);
        die(json_encode(['success' => false, 'message' => 'La contraseña actual no es correcta']));
    }

    $hash = password_hash($nueva, PASSWORD_DEFAULT);
    $stmtU = $pdo->prepare("UPDATE usuarios SET password = ? WHERE id = ?");
    $stmtU->execute([$hash, $userId]);

    registrarAuditoria('usuarios', $userId, 'UPDATE', null, [
        'password_cambiada' => true,
        'origen'            => 'cambio propio (Ajustes)',
    ]);

    echo json_encode(['success' => true, 'message' => 'Contraseña actualizada correctamente']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error del servidor: ' . $e->getMessage()]);
}
