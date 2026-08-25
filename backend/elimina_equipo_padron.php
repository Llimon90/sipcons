<?php
require_once __DIR__ . '/../auth/middleware.php';
header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
        throw new Exception("Método no permitido");
    }

    $id = $_GET['id'] ?? throw new Exception("ID de equipo no especificado");

    $pdo->beginTransaction();

    $stmtEquipo = $pdo->prepare("SELECT venta_id, venta_detalle_id, numero_serie, origen FROM padron_equipos WHERE id = ?");
    $stmtEquipo->execute([$id]);
    $equipo = $stmtEquipo->fetch(PDO::FETCH_ASSOC);

    if (!$equipo) {
        throw new Exception("Equipo no encontrado");
    }

    // Si el equipo viene de una venta propia (no de un equipo externo del cliente),
    // también quitamos su línea de venta_detalles para que la cantidad y el listado
    // en Ventas queden consistentes con lo que quedó en el Padrón.
    if ($equipo['origen'] === 'Venta SIPCONS' && !empty($equipo['venta_id'])) {
        if (!empty($equipo['venta_detalle_id'])) {
            // Enlace exacto: sin ambigüedad posible.
            $stmtDetalle = $pdo->prepare("DELETE FROM venta_detalles WHERE id = ?");
            $stmtDetalle->execute([$equipo['venta_detalle_id']]);
        } else {
            // Equipo antiguo sin el enlace directo: solo borramos si el emparejamiento
            // por (venta_id, numero_serie) es único, para no arriesgarnos a borrar la
            // línea equivocada cuando varios equipos comparten numero_serie = 'S/N'.
            $stmtBuscarDetalle = $pdo->prepare("SELECT id FROM venta_detalles WHERE venta_id = ? AND numero_serie = ?");
            $stmtBuscarDetalle->execute([$equipo['venta_id'], $equipo['numero_serie']]);
            $detallesCoincidentes = $stmtBuscarDetalle->fetchAll(PDO::FETCH_COLUMN);

            if (count($detallesCoincidentes) === 1) {
                $stmtDetalle = $pdo->prepare("DELETE FROM venta_detalles WHERE id = ?");
                $stmtDetalle->execute([$detallesCoincidentes[0]]);
            }
        }
    }

    $sql = "DELETE FROM padron_equipos WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id]);

    $pdo->commit();

    echo json_encode(['exito' => true, 'mensaje' => 'Equipo eliminado correctamente.']);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['exito' => false, 'mensaje' => $e->getMessage()]);
}
?>