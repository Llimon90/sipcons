<?php
// Alta de un acceso al portal de autoservicio para un cliente, desde
// Clientes → Acceso al portal. La contraseña inicial la define aquí el
// Administrador/Administrativo (no hay correo real en pruebas todavía para
// mandarla); se puede cambiar después desde "Olvidé mi contraseña" en el
// portal una vez que haya SMTP configurado.
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../auth/permisos.php';
require_once __DIR__ . '/../auth/audit.php';
requirePermiso('clientes');

header('Content-Type: application/json');

$clienteId = filter_var($_POST['cliente_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$email = trim((string)($_POST['email'] ?? ''));
$password = (string)($_POST['password'] ?? '');

if (!$clienteId || $email === '' || strlen($password) < 8) {
    http_response_code(400);
    die(json_encode(['success' => false, 'error' => 'Cliente, correo y contraseña (mínimo 8 caracteres) son obligatorios']));
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    die(json_encode(['success' => false, 'error' => 'El correo no es válido']));
}

try {
    $stmt = $pdo->prepare("INSERT INTO portal_usuarios (cliente_id, email, password_hash, activo) VALUES (?, ?, ?, 1)");
    $stmt->execute([$clienteId, $email, password_hash($password, PASSWORD_DEFAULT)]);
    $nuevoId = (int)$pdo->lastInsertId();

    registrarAuditoria('portal_usuarios', $nuevoId, 'CREATE', null, ['cliente_id' => $clienteId, 'email' => $email]);
    echo json_encode(['success' => true, 'id' => $nuevoId]);
} catch (PDOException $e) {
    if ($e->getCode() === '23000') {
        http_response_code(409);
        die(json_encode(['success' => false, 'error' => 'Ya existe un acceso de portal con ese correo']));
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'No se pudo crear el acceso']);
}
