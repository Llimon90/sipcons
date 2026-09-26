<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/permisos.php';
require_once __DIR__ . '/analytics_acceso.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['authenticated' => false]);
    exit;
}

echo json_encode([
    'authenticated' => true,
    'user'          => currentUser(),
    'modulos'       => modulosPermitidos(),
    'analiticas'    => puedeVerAnaliticas(),
]);
