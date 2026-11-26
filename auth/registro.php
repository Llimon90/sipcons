<?php
// Registro autoservicio: crea una empresa en periodo de prueba (15 días), sus
// tablas y su administrador, e inicia sesión. Se puede cerrar con
// REGISTRO_ABIERTO=false en .env.
//
// Protección contra abuso (sin depender de servicios externos):
//   - campo trampa "sitio_web" que un humano no ve ni llena
//   - tiempo mínimo entre que se muestra el formulario y se envía
//   - tope de registros por IP en 24 horas (REGISTRO_MAX_POR_IP, 3)
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/plataforma.php';
require_once __DIR__ . '/../config/branding.php';
require_once __DIR__ . '/../config/login_log.php';

header('Content-Type: application/json');

function rechazar(string $mensaje, int $codigo = 400): void {
    http_response_code($codigo);
    die(json_encode(['success' => false, 'message' => $mensaje], JSON_UNESCAPED_UNICODE));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') rechazar('Método no permitido', 405);
if (!registroAbierto()) rechazar('El registro de nuevas cuentas está cerrado por el momento. Escríbenos para darte acceso.', 403);

$d = json_decode(file_get_contents('php://input'), true) ?: [];

// Bots: campo trampa lleno o envío instantáneo. Se responde como si fuera un
// error genérico para no darles pistas.
$transcurrido = (int)(microtime(true) * 1000) - (int)($d['t'] ?? 0);
if (!empty($d['sitio_web']) || empty($d['t']) || $transcurrido < 3000 || $transcurrido > 86400000) {
    error_log('Registro rechazado por protección anti-bots desde ' . ipCliente());
    rechazar('No se pudo completar el registro. Recarga la página e inténtalo de nuevo.');
}

$empresaNombre = trim((string)($d['empresa'] ?? ''));
$nombre        = trim((string)($d['nombre'] ?? ''));
$correo        = strtolower(trim((string)($d['correo'] ?? '')));
$telefono      = trim((string)($d['telefono'] ?? ''));
$password      = (string)($d['password'] ?? '');

if (mb_strlen($empresaNombre) < 2 || mb_strlen($empresaNombre) > 150) rechazar('Escribe el nombre de tu empresa.');
if (mb_strlen($nombre) < 2 || mb_strlen($nombre) > 100) rechazar('Escribe tu nombre.');
if (!filter_var($correo, FILTER_VALIDATE_EMAIL) || strlen($correo) > 100) rechazar('Escribe un correo válido.');
if (mb_strlen($telefono) > 20) rechazar('El teléfono es demasiado largo.');
if (strlen($password) < 8) rechazar('La contraseña debe tener al menos 8 caracteres.');
if (empty($d['acepto'])) rechazar('Debes aceptar el aviso de privacidad y los términos para continuar.');

// Tope de registros por IP
$maxPorIp = registroMaxPorIp();
$stmt = $pdo->prepare("SELECT COUNT(*) FROM empresas WHERE registro_ip = ? AND creado_en >= NOW() - INTERVAL 1 DAY");
$stmt->execute([ipCliente()]);
if ((int)$stmt->fetchColumn() >= $maxPorIp) {
    rechazar('Se alcanzó el límite de registros desde tu conexión por hoy. Escríbenos y te damos acceso.', 429);
}

try {
    $empresaId = crearEmpresa($pdo, [
        'nombre'            => $empresaNombre,
        'estado'            => 'prueba',
        'admin_nombre'      => $nombre,
        'admin_usuario'     => $correo,
        'admin_password'    => $password,
        'admin_telefono'    => $telefono,
        'contacto_correo'   => $correo,
        'contacto_telefono' => $telefono ?: null,
        'registro_ip'       => ipCliente(),
        'marca'             => ['zona_horaria' => zonaHorariaRegistro($d['zona_horaria'] ?? '')],  // D-29
    ]);
} catch (InvalidArgumentException $e) {
    rechazar($e->getMessage() === 'Ya existe una cuenta con ese correo o usuario.'
        ? 'Ya existe una cuenta con ese correo. Inicia sesión o usa otro correo.'
        : $e->getMessage());
} catch (Throwable $e) {
    error_log('Error en registro autoservicio: ' . $e->getMessage());
    rechazar('No se pudo crear tu cuenta en este momento. Inténtalo de nuevo en unos minutos.', 500);
}

registrarEventoPlataforma($pdo, $empresaId, 'registro', [
    'empresa' => $empresaNombre, 'correo' => $correo, 'telefono' => $telefono,
]);
// Constancia de la aceptación (Código de Comercio, art. 89 y siguientes): qué textos, cuándo y desde dónde
$marcaLegal = brandConfig();
registrarEventoPlataforma($pdo, $empresaId, 'terminos_aceptados', [
    'correo' => $correo, 'fecha' => date('c'), 'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
    'aviso_privacidad' => $marcaLegal['aviso_privacidad'] ?? '', 'terminos' => $marcaLegal['terminos'] ?? '',
    'terminos_version' => $marcaLegal['terminos_version'] ?? '',
    'navegador' => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
]);

// Inicia sesión directamente como administrador de su nueva empresa
$stmt = $pdo->prepare("SELECT id, nombre, usuario, rol, password FROM usuarios WHERE usuario = ? AND empresa_id = ?");
$stmt->execute([$correo, $empresaId]);
$usuario = $stmt->fetch(PDO::FETCH_ASSOC);

session_regenerate_id(true);
$_SESSION['empresa_id'] = $empresaId;
$_SESSION['user_id']    = $usuario['id'];
$_SESSION['nombre']     = $usuario['nombre'];
$_SESSION['usuario']    = $usuario['usuario'];
$_SESSION['rol']        = $usuario['rol'];
actualizarHuellaSesion((string)$usuario['password']);
logLoginEvent('REGISTRO', $correo, 'empresa=' . $empresaId);

echo json_encode(['success' => true, 'redirect' => '../index.html'], JSON_UNESCAPED_UNICODE);
