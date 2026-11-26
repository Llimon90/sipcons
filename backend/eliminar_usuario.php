<?php
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../auth/audit.php';
require_once __DIR__ . '/../auth/permisos.php';
requirePermiso('usuarios');
require_once __DIR__ . '/../config/demo.php';
require_once __DIR__ . '/../config/usuarios.php';
header('Content-Type: application/json');

demoBloquear('eliminar usuarios');

// Borrar es una acción que cambia datos: solo POST (un enlace o <img> con
// ?id= en otra página no puede disparar la eliminación)
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die(json_encode(['success' => false, 'message' => 'Método no permitido']));
}

$entrada = json_decode(file_get_contents('php://input'), true) ?: [];
$idSolicitado = (int)($entrada['id'] ?? 0);

if ($idSolicitado === (int)($_SESSION['user_id'] ?? 0)) {
    http_response_code(400);
    die(json_encode(['success' => false, 'message' => 'No puedes eliminar tu propia cuenta.']));
}

if ($idSolicitado > 0) {
    $id = $idSolicitado;

    try {
        // Estado anterior para el historial (nunca se incluye la contraseña)
        $empresaId = (int)$_SESSION['empresa_id'];
        $stmtAnterior = $conn->prepare("SELECT nombre, correo, telefono, usuario, rol FROM usuarios WHERE id = ? AND empresa_id = ?");
        $stmtAnterior->bind_param("ii", $id, $empresaId);
        $stmtAnterior->execute();
        $anterior = $stmtAnterior->get_result()->fetch_assoc();
        $stmtAnterior->close();
        if (!$anterior) {
            http_response_code(404);
            die(json_encode(['success' => false, 'message' => 'Usuario no encontrado']));
        }
        $errorPermiso = errorGestionCuenta(normalizarRol((string)$anterior['rol']), null);
        if (!$errorPermiso && normalizarRol((string)$anterior['rol']) === ROL_ADMINISTRADOR && contarAdministradores($pdo, $empresaId) <= 1) {
            $errorPermiso = 'Es el único Administrador de la empresa: no se puede eliminar.';
        }
        if ($errorPermiso) {
            http_response_code(403);
            die(json_encode(['success' => false, 'message' => $errorPermiso], JSON_UNESCAPED_UNICODE));
        }

        // D-11: su nombre sale de las órdenes activas (las cerradas lo conservan)
        require_once __DIR__ . '/lib/tecnicos_helpers.php';
        $st = $pdo->prepare('SELECT ' . sqlIdentidadUsuario($pdo) . ' FROM usuarios WHERE id = ? AND empresa_id = ?');
        $st->execute([$id, $empresaId]);
        $identidad = (string)$st->fetchColumn();

        $pdo->beginTransaction();
        $stmt = $pdo->prepare("DELETE FROM usuarios WHERE id = ? AND empresa_id = ?");
        $stmt->execute([$id, $empresaId]);

        if ($stmt->rowCount() > 0 && $identidad !== '') {
            reemplazarTecnicoEnOrdenesActivas($pdo, $identidad, null, 'Se eliminó al usuario ' . $identidad);
        }
        $pdo->commit();
        registrarAuditoria('usuarios', $id, 'DELETE', $anterior ?: null, null);

        echo json_encode(['success' => true]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Eliminar usuario: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'No se pudo eliminar el usuario. Inténtalo de nuevo.'], JSON_UNESCAPED_UNICODE);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'ID de usuario no proporcionado.']);
}
?>
