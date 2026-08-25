<?php
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../auth/permisos.php';
requireGestionPrivilegios();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../auth/audit.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    $data = json_decode(file_get_contents('php://input'), true);
    $matrizNueva = $data['matriz'] ?? null;

    if (!is_array($matrizNueva)) {
        http_response_code(400);
        die(json_encode(['success' => false, 'message' => 'Formato de datos inválido']));
    }

    // Foto del estado anterior, para el historial
    $stmtAntes = $pdo->query("SELECT rol, modulo, permitido FROM permisos_rol");
    $matrizAntes = [];
    foreach ($stmtAntes->fetchAll(PDO::FETCH_ASSOC) as $fila) {
        $matrizAntes[$fila['rol']][$fila['modulo']] = (bool)$fila['permitido'];
    }

    $pdo->beginTransaction();

    $stmtUpsert = $pdo->prepare(
        "INSERT INTO permisos_rol (rol, modulo, permitido) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE permitido = VALUES(permitido)"
    );

    $matrizGuardada = [];
    foreach (ROLES_CONFIGURABLES as $rol) {
        $matrizGuardada[$rol] = [];
        foreach (MODULOS_PERMISOS as $modulo) {
            // Solo aceptamos combinaciones rol/modulo conocidas: cualquier
            // clave extra que llegue en el POST se ignora silenciosamente.
            $permitido = !empty($matrizNueva[$rol][$modulo]) ? 1 : 0;
            $stmtUpsert->execute([$rol, $modulo, $permitido]);
            $matrizGuardada[$rol][$modulo] = (bool)$permitido;
        }
    }

    $pdo->commit();

    registrarAuditoria('permisos_rol', null, 'UPDATE', $matrizAntes, $matrizGuardada);

    echo json_encode(['success' => true, 'matriz' => $matrizGuardada]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error al guardar privilegios: ' . $e->getMessage()]);
}
