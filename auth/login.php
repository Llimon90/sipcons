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

$stmt = $conn->prepare(
    "SELECT id, nombre, usuario, password, rol FROM usuarios WHERE usuario = ? LIMIT 1"
);
$stmt->bind_param('s', $usuario);
$stmt->execute();
$result = $stmt->get_result();
$user   = $result->fetch_assoc();
$stmt->close();

if (!$user || !password_verify($password, $user['password'])) {
    http_response_code(401);
    die(json_encode(['success' => false, 'message' => 'Usuario o contraseña incorrectos']));
}

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
