<?php
// Login del portal de autoservicio. Sesión y cookie totalmente separadas del
// login de personal (ver auth/portal_middleware.php).
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
$password = (string)($_POST['password'] ?? '');

if ($email === '' || $password === '') {
    http_response_code(400);
    die(json_encode(['success' => false, 'error' => 'Correo y contraseña son requeridos']));
}

$stmt = $pdo->prepare("SELECT pu.*, c.nombre AS cliente_nombre FROM portal_usuarios pu
    JOIN clientes c ON c.id = pu.cliente_id
    WHERE pu.email = ? LIMIT 1");
$stmt->execute([$email]);
$usuario = $stmt->fetch(PDO::FETCH_ASSOC);

// Mismo mensaje genérico exista o no la cuenta: no se confirma si un correo
// está registrado en el portal.
$credencialesInvalidas = ['success' => false, 'error' => 'Correo o contraseña incorrectos'];

if (!$usuario || !$usuario['activo']) {
    registrarAuditoria('portal_usuarios', null, 'LOGIN_FALLO', null, ['email' => $email, 'motivo' => $usuario ? 'inactivo' : 'no_existe']);
    http_response_code(401);
    die(json_encode($credencialesInvalidas));
}

if (!empty($usuario['bloqueado_hasta']) && strtotime($usuario['bloqueado_hasta']) > time()) {
    http_response_code(423);
    die(json_encode(['success' => false, 'error' => 'Cuenta bloqueada temporalmente por varios intentos fallidos. Intenta más tarde.']));
}

if (!password_verify($password, $usuario['password_hash'])) {
    $intentos = (int)$usuario['intentos_fallidos'] + 1;
    $bloqueadoHasta = null;
    if ($intentos >= PORTAL_INTENTOS_MAXIMOS) {
        $bloqueadoHasta = date('Y-m-d H:i:s', time() + PORTAL_BLOQUEO_MINUTOS * 60);
        $intentos = 0;
    }
    $pdo->prepare("UPDATE portal_usuarios SET intentos_fallidos = ?, bloqueado_hasta = ? WHERE id = ?")
        ->execute([$intentos, $bloqueadoHasta, $usuario['id']]);

    registrarAuditoria('portal_usuarios', $usuario['id'], 'LOGIN_FALLO', null, ['email' => $email, 'motivo' => 'password_incorrecto']);
    http_response_code(401);
    die(json_encode($credencialesInvalidas));
}

// Login correcto: limpia intentos, regenera el id de sesión (evita fijación
// de sesión) y guarda solo lo necesario para identificar al cliente.
$pdo->prepare("UPDATE portal_usuarios SET intentos_fallidos = 0, bloqueado_hasta = NULL, ultimo_acceso = NOW() WHERE id = ?")
    ->execute([$usuario['id']]);

session_regenerate_id(true);
$_SESSION['portal_usuario_id'] = (int)$usuario['id'];
$_SESSION['portal_cliente_id'] = (int)$usuario['cliente_id'];
$_SESSION['portal_email'] = $usuario['email'];

registrarAuditoria('portal_usuarios', $usuario['id'], 'LOGIN', null, ['email' => $email]);

echo json_encode([
    'success' => true,
    'cliente' => ['nombre' => $usuario['cliente_nombre']],
]);
