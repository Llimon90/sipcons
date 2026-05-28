<?php
header('Content-Type: application/json');
ini_set('display_errors', 0); // Ocultar errores HTML para no romper el JSON
require_once 'conexion.php';

try {
    // Capturar el ID que envía el Fetch (JSON)
    $json = file_get_contents('php://input');
    $data = json_decode($json, true);
    
    $idVenta = $data['id'] ?? null;

    if (!$idVenta) {
        throw new Exception("No se recibió el ID de la venta a eliminar.");
    }

    $pdo->beginTransaction();

    // ==========================================
    // 1. Borrar los archivos físicos del disco duro
    // ==========================================
    $stmtArchivos = $pdo->prepare("SELECT ruta_archivo FROM venta_archivos WHERE venta_id = ?");
    $stmtArchivos->execute([$idVenta]);
    $archivos = $stmtArchivos->fetchAll(PDO::FETCH_ASSOC);

    foreach ($archivos as $archivo) {
        if (!empty($archivo['ruta_archivo']) && file_exists($archivo['ruta_archivo'])) {
            unlink($archivo['ruta_archivo']);
        }
    }

    // ==========================================
    // 2. Borrar registros "Hijos" (Para evitar bloqueo de Llave Foránea)
    // ==========================================
    $stmtDelArchivos = $pdo->prepare("DELETE FROM venta_archivos WHERE venta_id = ?");
    $stmtDelArchivos->execute([$idVenta]);

    $stmtDelDetalles = $pdo->prepare("DELETE FROM venta_detalles WHERE venta_id = ?");
    $stmtDelDetalles->execute([$idVenta]);

    // ==========================================
    // 3. Borrar el registro "Padre" (La venta principal)
    // ==========================================
    $stmtDelete = $pdo->prepare("DELETE FROM ventas WHERE id = ?");
    $stmtDelete->execute([$idVenta]);

    // Si todo salió bien, confirmamos la transacción
    $pdo->commit();
    echo json_encode(['exito' => true, 'mensaje' => 'Venta eliminada por completo.']);

} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("Error SQL al eliminar venta: " . $e->getMessage());
    echo json_encode(['exito' => false, 'mensaje' => 'Error de Base de Datos: ' . $e->getMessage()]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['exito' => false, 'mensaje' => $e->getMessage()]);
}
?>