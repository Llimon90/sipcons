<?php
// Solicitud de restablecimiento de contraseña del portal.
//
// TEMPORAL mientras no haya SMTP configurado en el sitio de pruebas: el
// enlace con el token se escribe en storage/logs/portal_reset.log (fuera del
// webroot) para que el administrador lo copie y lo pase al cliente a mano.
// El token NUNCA se regresa en la respuesta JSON al navegador: eso sería una
// fuga equivalente a mandarlo por correo a cualquiera que adivine un email.
require_once __DIR__ . '/../../auth/portal_middleware.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    die(json_encode(['success' => false, 'error' => 'Método no permitido']));
}

$email = trim((string)($_POST['email'] ?? ''));

// Respuesta genérica siempre: no se confirma si el correo existe en el portal.
$respuestaGenerica = ['success' => true, 'message' => 'Si el correo está registrado, se generó un enlace de restablecimiento.'];

if ($email === '') {
    echo json_encode($respuestaGenerica);
    exit;
}

$stmt = $pdo->prepare("SELECT id FROM portal_usuarios WHERE email = ? AND activo = 1 LIMIT 1");
$stmt->execute([$email]);
$usuario = $stmt->fetch(PDO::FETCH_ASSOC);

if ($usuario) {
    $token = bin2hex(random_bytes(32));
    $expira = date('Y-m-d H:i:s', time() + PORTAL_RESET_EXPIRA_MINUTOS * 60);
    $pdo->prepare("UPDATE portal_usuarios SET token_reset = ?, token_reset_expira = ? WHERE id = ?")
        ->execute([hash('sha256', $token), $expira, $usuario['id']]);

    $logDir = __DIR__ . '/../../storage/logs';
    if (!is_dir($logDir)) @mkdir($logDir, 0700, true);
    $appUrl = rtrim($_ENV['APP_URL'] ?? '', '/');
    $enlace = $appUrl . '/public/portal/recuperar.html?token=' . $token . '&email=' . rawurlencode($email);
    @file_put_contents(
        $logDir . '/portal_reset.log',
        date('Y-m-d H:i:s') . " email=$email enlace=$enlace\n",
        FILE_APPEND
    );
}

echo json_encode($respuestaGenerica);
