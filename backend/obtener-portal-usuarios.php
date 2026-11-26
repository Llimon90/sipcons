<?php
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../auth/permisos.php';
requirePermiso('clientes');

$clienteId = filter_var($_GET['cliente_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$clienteId) {
    http_response_code(400);
    die(json_encode(['success' => false, 'error' => 'cliente_id inválido']));
}

$stmt = $pdo->prepare("SELECT id, email, activo, ultimo_acceso, creado_en FROM portal_usuarios WHERE cliente_id = ? ORDER BY creado_en DESC");
$stmt->execute([$clienteId]);
echo json_encode(['success' => true, 'usuarios' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
