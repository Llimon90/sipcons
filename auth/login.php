<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die(json_encode(['error' => 'Método no permitido']));
}

$usuario  = trim($_POST['usuario'] ?? '');
$password = $_POST['password'] ?? '';

if (empty($usuario) || empty($password)) {
    http_response_code(400);
    die(json_encode(['success' => false, 'message' => 'Usuario y contraseña son requeridos']));
}

// --- Rate limiting de intentos de login ---
const MAX_INTENTOS_LOGIN  = 5;
const BLOQUEO_MINUTOS_LOGIN = 15;

$conn->query(
    "CREATE TABLE IF NOT EXISTS login_intentos (
        identificador VARCHAR(191) NOT NULL PRIMARY KEY,
        intentos INT NOT NULL DEFAULT 0,
        bloqueado_hasta DATETIME NULL,
        actualizado_en DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

$ip = $_SERVER['REMOTE_ADDR'] ?? 'desconocida';
$identificador = mb_strtolower($usuario) . '|' . $ip;

$stmtCheck = $conn->prepare("SELECT intentos, bloqueado_hasta FROM login_intentos WHERE identificador = ?");
$stmtCheck->bind_param('s', $identificador);
$stmtCheck->execute();
$intento = $stmtCheck->get_result()->fetch_assoc();
$stmtCheck->close();

if ($intento && $intento['bloqueado_hasta'] !== null && strtotime($intento['bloqueado_hasta']) > time()) {
    http_response_code(429);
    die(json_encode([
        'success' => false,
        'message' => 'Demasiados intentos fallidos. Intenta de nuevo en unos minutos.',
    ]));
}

$stmt = $conn->prepare(
    "SELECT id, nombre, usuario, password, rol FROM usuarios WHERE usuario = ? LIMIT 1"
);
$stmt->bind_param('s', $usuario);
$stmt->execute();
$result = $stmt->get_result();
$user   = $result->fetch_assoc();
$stmt->close();

if (!$user || !password_verify($password, $user['password'])) {
    $intentosNuevos = ($intento['intentos'] ?? 0) + 1;
    $bloqueadoHasta = $intentosNuevos >= MAX_INTENTOS_LOGIN
        ? date('Y-m-d H:i:s', time() + BLOQUEO_MINUTOS_LOGIN * 60)
        : null;

    $stmtUpsert = $conn->prepare(
        "INSERT INTO login_intentos (identificador, intentos, bloqueado_hasta, actualizado_en)
         VALUES (?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE intentos = ?, bloqueado_hasta = ?, actualizado_en = NOW()"
    );
    $stmtUpsert->bind_param('sisis', $identificador, $intentosNuevos, $bloqueadoHasta, $intentosNuevos, $bloqueadoHasta);
    $stmtUpsert->execute();
    $stmtUpsert->close();

    http_response_code(401);
    die(json_encode(['success' => false, 'message' => 'Usuario o contraseña incorrectos']));
}

// Login correcto: limpiar contador de intentos
$stmtReset = $conn->prepare("DELETE FROM login_intentos WHERE identificador = ?");
$stmtReset->bind_param('s', $identificador);
$stmtReset->execute();
$stmtReset->close();

session_regenerate_id(true);

$_SESSION['user_id'] = $user['id'];
$_SESSION['nombre']  = $user['nombre'];
$_SESSION['usuario'] = $user['usuario'];
$_SESSION['rol']     = $user['rol'];

echo json_encode([
    'success'  => true,
    'redirect' => '../index.html',
    'user'     => [
        'nombre'  => $user['nombre'],
        'usuario' => $user['usuario'],
        'rol'     => $user['rol'],
    ],
]);
