<?php
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../auth/permisos.php';
requirePermisoAlguno('clientes');
require_once __DIR__ . '/../auth/audit.php';
header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
        throw new Exception("Método no permitido");
    }

    $id = $_GET['id'] ?? throw new Exception("ID de equipo no especificado");

    $pdo->beginTransaction();

    $stmtEquipo = $pdo->prepare(
        "SELECT cliente, sucursal, equipo, marca, modelo, numero_serie, calibracion, servicio, frecuencia_servicio, garantia, origen, venta_id, venta_detalle_id
         FROM padron_equipos WHERE id = ?"
    );
    $stmtEquipo->execute([$id]);
    $equipo = $stmtEquipo->fetch(PDO::FETCH_ASSOC);

    if (!$equipo) {
        throw new Exception("Equipo no encontrado");
    }

    // L-6: un equipo de una venta propia se corrige desde la venta. Quitarlo de aquí cambiaba
    // la venta (un documento) y descuadraba el inventario al volver a editarla.
    if ($equipo['origen'] === 'Venta propia' && !empty($equipo['venta_id'])) {
        $st = $pdo->prepare('SELECT folio FROM ventas WHERE id = ?');
        $st->execute([(int)$equipo['venta_id']]);
        if ($folioVenta = $st->fetchColumn()) {
            $pdo->rollBack();
            http_response_code(409);
            die(json_encode(['exito' => false, 'codigo' => 'equipo_de_venta',
                'mensaje' => "Este equipo es de la venta $folioVenta: quítalo o corrígelo desde la venta."], JSON_UNESCAPED_UNICODE));
        }
    }

    $sql = "DELETE FROM padron_equipos WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id]);
    // L-29: las órdenes que lo atendieron conservan la serie como texto, sin liga colgando
    try { $pdo->prepare('UPDATE incidencia_equipos SET padron_id = NULL WHERE padron_id = ?')->execute([$id]); } catch (PDOException $e) { /* sin D-41 */ }

    $pdo->commit();

    registrarAuditoria('padron_equipos', $id, 'DELETE', $equipo, null);

    echo json_encode(['exito' => true, 'mensaje' => 'Equipo eliminado correctamente.']);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['exito' => false, 'mensaje' => mensajeErrorPublico($e)]);
}
?>