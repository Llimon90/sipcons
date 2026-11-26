<?php
require_once __DIR__ . '/../../auth/portal_middleware.php';
require_once __DIR__ . '/../../auth/audit.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    die(json_encode(['success' => false, 'error' => 'Método no permitido']));
}

$email = trim((string)($_POST['email'] ?? ''));
$token = (string)($_POST['token'] ?? '');
$passwordNueva = (string)($_POST['password_nueva'] ?? '');

if ($email === '' || $token === '' || strlen($passwordNueva) < 8) {
    http_response_code(400);
    die(json_encode(['success' => false, 'error' => 'Datos incompletos o contraseña demasiado corta (mínimo 8 caracteres)']));
}

$stmt = $pdo->prepare("SELECT id, token_reset, token_reset_expira FROM portal_usuarios WHERE email = ? AND activo = 1 LIMIT 1");
$stmt->execute([$email]);
$usuario = $stmt->fetch(PDO::FETCH_ASSOC);

$tokenValido = $usuario
    && $usuario['token_reset']
    && hash_equals($usuario['token_reset'], hash('sha256', $token))
    && !empty($usuario['token_reset_expira'])
    && strtotime($usuario['token_reset_expira']) > time();

if (!$tokenValido) {
    http_response_code(400);
    die(json_encode(['success' => false, 'error' => 'El enlace no es válido o ya venció. Solicita uno nuevo.']));
}

$pdo->prepare("UPDATE portal_usuarios SET password_hash = ?, token_reset = NULL, token_reset_expira = NULL, intentos_fallidos = 0, bloqueado_hasta = NULL WHERE id = ?")
    ->execute([password_hash($passwordNueva, PASSWORD_DEFAULT), $usuario['id']]);

registrarAuditoria('portal_usuarios', $usuario['id'], 'RESET_PASSWORD', null, ['email' => $email]);

echo json_encode(['success' => true, 'message' => 'Contraseña actualizada. Ya puedes iniciar sesión.']);
