<?php
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../auth/audit.php';
require_once __DIR__ . '/../config/demo.php';
header('Content-Type: application/json');

demoBloquear('el cambio de contraseña');
if (!empty($_SESSION['soporte'])) {
    http_response_code(403);
    die(json_encode(['success' => false, 'message' => 'En modo soporte no se cambian contraseñas. Hazlo desde el panel de plataforma.']));
}

try {
    $data = json_decode(file_get_contents('php://input'), true);
    $actual = $data['password_actual'] ?? '';
    $nueva  = $data['password_nueva'] ?? '';

    if (empty($actual) || empty($nueva)) {
        http_response_code(400);
        die(json_encode(['success' => false, 'message' => 'Completa la contraseña actual y la nueva']));
    }

    require_once __DIR__ . '/../config/usuarios.php';
    if (strlen($nueva) < MIN_PASSWORD_USUARIO) {
        http_response_code(400);
        die(json_encode(['success' => false, 'message' => 'La nueva contraseña debe tener al menos ' . MIN_PASSWORD_USUARIO . ' caracteres'], JSON_UNESCAPED_UNICODE));
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
    if ($nueva === $actual) {
        http_response_code(400);
        die(json_encode(['success' => false, 'message' => 'La nueva contraseña debe ser distinta de la actual'], JSON_UNESCAPED_UNICODE));
    }
    $stmtU = $pdo->prepare("UPDATE usuarios SET password = ? WHERE id = ?");
    $stmtU->execute([$hash, $userId]);
    if (usuariosTienenCambioPassword($pdo)) {  // L-13: ya no es temporal
        $pdo->prepare('UPDATE usuarios SET debe_cambiar_password = 0 WHERE id = ?')->execute([$userId]);
    }
    actualizarHuellaSesion($hash); // esta sesión sigue; las de otros dispositivos se cierran

    registrarAuditoria('usuarios', $userId, 'UPDATE', null, [
        'password_cambiada' => true,
        'origen'            => 'cambio propio (Ajustes)',
    ]);

    echo json_encode(['success' => true, 'message' => 'Contraseña actualizada correctamente']);
} catch (Throwable $e) {
    error_log('Cambio de contraseña: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'No se pudo cambiar la contraseña. Inténtalo de nuevo.'], JSON_UNESCAPED_UNICODE);
}
