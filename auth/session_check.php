<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/permisos.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['authenticated' => false]);
    exit;
}

// Tema (claro/oscuro) guardado en la cuenta; si la migración 012 aún no se
// aplicó, se ignora y el sistema sigue en modo claro.
$tema = 'claro';
try {
    $stmtTema = $pdo->prepare('SELECT tema FROM usuarios WHERE id = ? LIMIT 1');
    $stmtTema->execute([$_SESSION['user_id']]);
    $guardado = $stmtTema->fetchColumn();
    if ($guardado === 'oscuro') $tema = 'oscuro';
} catch (\Throwable $e) {
    error_log('No se pudo leer el tema del usuario (¿falta la migración 012?): ' . $e->getMessage());
}

echo json_encode([
    'authenticated' => true,
    'user'          => currentUser(),
    'modulos'       => modulosPermitidos(),
    'tema'          => $tema,
]);
