<?php
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../auth/permisos.php';
requireGestionPrivilegios();
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    $stmt = $pdo->query("SELECT rol, modulo, permitido FROM permisos_rol");
    $filas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Armamos la matriz completa (rol -> modulo -> bool), con 0 por defecto
    // para cualquier combinación que aún no exista en la tabla.
    $matriz = [];
    foreach (ROLES_CONFIGURABLES as $rol) {
        $matriz[$rol] = array_fill_keys(MODULOS_PERMISOS, false);
    }
    foreach ($filas as $fila) {
        if (isset($matriz[$fila['rol']]) && in_array($fila['modulo'], MODULOS_PERMISOS, true)) {
            $matriz[$fila['rol']][$fila['modulo']] = (bool)$fila['permitido'];
        }
    }

    echo json_encode([
        'success' => true,
        'roles'   => ROLES_CONFIGURABLES,
        'modulos' => MODULOS_PERMISOS,
        'matriz'  => $matriz,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error al obtener privilegios: ' . $e->getMessage()]);
}
