<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/login_log.php';
require_once __DIR__ . '/../config/contratacion.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die(json_encode(['error' => 'Método no permitido']));
}

$usuario  = strtolower(trim($_POST['usuario'] ?? ''));
$password = $_POST['password'] ?? '';

if (empty($usuario) || empty($password)) {
    logLoginEvent('FALLO', $usuario, 'campos vacios');
    http_response_code(400);
    die(json_encode(['success' => false, 'message' => 'Escribe tu correo y tu contraseña']));
}

$minutos = loginMinutosBloqueo($pdo, $usuario);
if ($minutos > 0) {
    logLoginEvent('BLOQUEADO', $usuario, 'demasiados intentos');
    http_response_code(429);
    die(json_encode(['success' => false, 'message' => "Demasiados intentos fallidos. Por seguridad, espera $minutos " . ($minutos === 1 ? 'minuto' : 'minutos') . ' e inténtalo de nuevo.'], JSON_UNESCAPED_UNICODE));
}

$stmt = $conn->prepare(
    "SELECT id, empresa_id, nombre, usuario, password, rol FROM usuarios WHERE LOWER(usuario) = ? LIMIT 1"
);
$stmt->bind_param('s', $usuario);
$stmt->execute();
$result = $stmt->get_result();
$user   = $result->fetch_assoc();
$stmt->close();

if (!$user || !password_verify($password, $user['password'])) {
    logLoginEvent('FALLO', $usuario, $user ? 'password incorrecto' : 'usuario inexistente');
    loginRegistrarFallo($pdo, $usuario);
    http_response_code(401);
    die(json_encode(['success' => false, 'message' => 'Correo o contraseña incorrectos']));
}

$user['rol'] = normalizarRol((string)$user['rol']); // roles anteriores a la migración 006

// La empresa debe estar en condiciones de operar (prueba vigente, pagos al día...)
$empresaId = $user['empresa_id'] !== null ? (int)$user['empresa_id'] : null;
if ($empresaId === null && $user['rol'] !== ROL_SUPERADMIN) {
    logLoginEvent('FALLO', $usuario, 'usuario sin empresa');
    http_response_code(403);
    die(json_encode(['success' => false, 'message' => 'Tu cuenta no está asociada a ninguna empresa.']));
}
// Prueba o pago vencido: el administrador entra solo a Contratar plan para
// regularizar la cuenta (requireAuth sigue bloqueando todo lo demás).
$soloContratar = false;
if ($empresaId !== null) {
    establecerEmpresa($empresaId);
    $situacion = situacionEmpresa(empresaActual());
    $soloContratar = $situacion['acceso'] === 'bloqueado' && rolPuedeContratar($user['rol'])
        && empresaPuedeContratar(empresaActual()) && contratacionDisponible($pdo);
    if ($situacion['acceso'] === 'bloqueado' && !$soloContratar) {
        logLoginEvent('FALLO', $usuario, 'empresa ' . $situacion['estado']);
        http_response_code(403);
        die(json_encode(['success' => false, 'message' => $situacion['mensaje'], 'estado_empresa' => $situacion['estado']], JSON_UNESCAPED_UNICODE));
    }
}

loginLimpiarFallos($pdo, $usuario);
session_regenerate_id(true);

$_SESSION['empresa_id'] = $empresaId;
$_SESSION['user_id'] = $user['id'];
$_SESSION['nombre']  = $user['nombre'];
$_SESSION['usuario'] = $user['usuario'];
$_SESSION['rol']     = $user['rol'];
actualizarHuellaSesion((string)$user['password']);
// Al salir de la demo se vuelve a la página de la demo, no al login de clientes
$_SESSION['en_demo'] = $empresaId !== null && (empresaActual()['estado'] ?? '') === 'demo';

logLoginEvent('OK', $user['usuario'], 'rol=' . $user['rol'] . ($soloContratar ? ' (solo contratar)' : ''));

echo json_encode([
    'success'  => true,
    'redirect' => $empresaId === null ? '../plataforma/index.html' : ($soloContratar ? 'contratar.html' : '../index.html'),
    'user'     => [
        'nombre'  => $user['nombre'],
        'usuario' => $user['usuario'],
        'rol'     => $user['rol'],
    ],
]);
