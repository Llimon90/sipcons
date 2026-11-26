<?php
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../auth/permisos.php';
require_once __DIR__ . '/../auth/audit.php';
requireAuth();
// La aprobación es un control, no un privilegio configurable: solo
// Administrador/Técnico-Administrador/Programador pueden aprobar o rechazar
// un cambio, igual que requireGestionPrivilegios() en auth/permisos.php.
$rolSesion = $_SESSION['rol'] ?? '';
if (!in_array($rolSesion, ['Administrador', 'Técnico/Administrador', ROL_ACCESO_TOTAL], true)) {
    http_response_code(403);
    header('Content-Type: application/json');
    die(json_encode(['success' => false, 'message' => 'Solo un Administrador puede aprobar o rechazar un cambio.']));
}
header('Content-Type: application/json');

try {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $id = (int)($data['id'] ?? 0);
    $decision = (string)($data['decision'] ?? '');
    $comentario = trim((string)($data['comentario'] ?? ''));

    if ($id <= 0 || !in_array($decision, ['Aprobado', 'Rechazado'], true)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Falta el id del cambio o la decisión (Aprobado/Rechazado).']);
        exit;
    }

    $pdo->beginTransaction();

    $stmt = $pdo->prepare('SELECT * FROM cambios WHERE id = ? FOR UPDATE');
    $stmt->execute([$id]);
    $cambio = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$cambio) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'El cambio no existe.']);
        exit;
    }
    if ($cambio['estatus'] !== 'Pendiente') {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'Este cambio ya fue revisado (estatus actual: ' . $cambio['estatus'] . ').']);
        exit;
    }

    $aprobador = $_SESSION['nombre'] ?? ($_SESSION['usuario'] ?? 'Desconocido');
    $pdo->prepare(
        'UPDATE cambios SET estatus = ?, aprobador_id = ?, aprobador = ?, fecha_aprobacion = NOW(), comentario_aprobacion = ? WHERE id = ?'
    )->execute([$decision, $_SESSION['user_id'] ?? null, $aprobador, $comentario !== '' ? $comentario : null, $id]);

    $pdo->commit();

    registrarAuditoria('cambios', $id, 'UPDATE', $cambio, [
        'estatus' => $decision, 'aprobador' => $aprobador, 'comentario_aprobacion' => $comentario,
    ], $cambio['folio']);

    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error al registrar la decisión.']);
}
