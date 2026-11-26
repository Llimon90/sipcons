<?php
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../auth/permisos.php';
require_once __DIR__ . '/../auth/audit.php';
requirePermiso('cotizaciones');
header('Content-Type: application/json');

// Edición limitada a propósito: solo estatus (Pendiente/Aprobada/Rechazada) y
// notas. Las líneas y el total no se editan una vez creada la cotización; si
// cambió la propuesta, se cancela (Rechazada) y se hace una nueva — así el
// folio e historial de cada cotización siempre reflejan lo que realmente se
// mandó al cliente.
try {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $id = (int)($data['id'] ?? 0);
    $estatus = (string)($data['estatus'] ?? '');
    $notas = array_key_exists('notas', $data) ? trim((string)$data['notas']) : null;

    $estatusEditables = ['Pendiente', 'Aprobada', 'Rechazada'];
    if ($id <= 0 || !in_array($estatus, $estatusEditables, true)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Estatus inválido.']);
        exit;
    }

    $stmt = $pdo->prepare('SELECT * FROM cotizaciones WHERE id = ?');
    $stmt->execute([$id]);
    $anterior = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$anterior) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Cotización no encontrada.']);
        exit;
    }
    if ($anterior['estatus'] === 'Convertida') {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'Esta cotización ya se convirtió en venta y no se puede editar.']);
        exit;
    }

    if ($notas !== null) {
        $pdo->prepare('UPDATE cotizaciones SET estatus = ?, notas = ? WHERE id = ?')->execute([$estatus, $notas, $id]);
    } else {
        $pdo->prepare('UPDATE cotizaciones SET estatus = ? WHERE id = ?')->execute([$estatus, $id]);
    }

    registrarAuditoria('cotizaciones', $id, 'UPDATE', $anterior, ['estatus' => $estatus, 'notas' => $notas], $anterior['folio']);

    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error al actualizar la cotización.']);
}
