<?php
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../auth/permisos.php';
requirePermisoAlguno('ventas');
require_once __DIR__ . '/../auth/audit.php';
require_once __DIR__ . '/lib/inventario_helpers.php';
header('Content-Type: application/json');
ini_set('display_errors', 0); // Ocultar errores HTML para no romper el JSON

// Borrar una venta (con sus series, facturas y lo descontado del almacén) es
// definitivo: solo el Administrador, igual que las incidencias.
if (!esAdministracion()) {
    http_response_code(403);
    die(json_encode(['exito' => false, 'mensaje' => 'Solo un administrador puede eliminar ventas.'], JSON_UNESCAPED_UNICODE));
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die(json_encode(['exito' => false, 'mensaje' => 'Método no permitido']));
}

try {
    // Capturar el ID que envía el Fetch (JSON)
    $json = file_get_contents('php://input');
    $data = json_decode($json, true);
    
    $idVenta = (int)($data['id'] ?? 0);

    if (!$idVenta) {
        throw new Exception("No se recibió el ID de la venta a eliminar.");
    }

    // H-05: mismo orden de bloqueo que el cobro (orden -> cotización -> venta), para que eliminar y
    // cobrar la venta a la vez se serialicen. El plan se lee ANTES de abrir la transacción: la primera
    // lectura normal dentro de ella fija la foto de los datos (ver finPlanDeBloqueo).
    require_once __DIR__ . '/lib/finanzas_helpers.php';
    $planVenta = finPlanDeBloqueo($pdo, 'venta', $idVenta);
    $pdo->beginTransaction();
    finAplicarBloqueo($pdo, $planVenta);

    // Estado anterior para el historial (cabecera + equipos), antes de borrar nada
    $stmtCabAnterior = $pdo->prepare("SELECT folio, cliente, sucursal, fecha_registro FROM ventas WHERE id = ? FOR UPDATE");
    $stmtCabAnterior->execute([$idVenta]);
    $ventaAnterior = $stmtCabAnterior->fetch(PDO::FETCH_ASSOC);
    if (!$ventaAnterior) {
        $pdo->rollBack();
        http_response_code(404);
        die(json_encode(['exito' => false, 'mensaje' => 'La venta no existe o ya fue eliminada.'], JSON_UNESCAPED_UNICODE));
    }

    // H-08 · D-55: una venta con cuenta por cobrar vigente no se elimina (desligarla dejaría
    // volver a cobrar su cotización)
    require_once __DIR__ . '/lib/finanzas_helpers.php';
    if (finDisponible($pdo)) {
        $stCta = $pdo->prepare("SELECT folio FROM cuentas_cobrar WHERE origen_tipo = 'venta' AND origen_id = ? AND estatus <> 'cancelada' LIMIT 1");
        $stCta->execute([$idVenta]);
        if ($cuenta = $stCta->fetchColumn()) {
            $pdo->rollBack();
            http_response_code(409);
            die(json_encode(['exito' => false, 'mensaje' => "Esta venta se está cobrando con la cuenta $cuenta. Cancela esa cuenta en Finanzas antes de eliminar la venta."], JSON_UNESCAPED_UNICODE));
        }
    }

    $stmtDetAnterior = $pdo->prepare(
        "SELECT equipo, marca, modelo, numero_serie, garantia, calibracion, servicio, frecuencia_servicio
         FROM venta_detalles WHERE venta_id = ?"
    );
    $stmtDetAnterior->execute([$idVenta]);
    $ventaAnterior['equipos'] = $stmtDetAnterior->fetchAll(PDO::FETCH_ASSOC);

    // ==========================================
    // 1. Archivos físicos: se anotan y se borran solo después de confirmar
    //    (si algo falla, la venta y sus facturas quedan intactas)
    // ==========================================
    $stmtArchivos = $pdo->prepare("SELECT ruta_archivo FROM venta_archivos WHERE venta_id = ?");
    $stmtArchivos->execute([$idVenta]);
    $archivos = $stmtArchivos->fetchAll(PDO::FETCH_ASSOC);

    // ==========================================
    // 2. Borrar registros "Hijos" (Para evitar bloqueo de Llave Foránea)
    // ==========================================
    $stmtDelArchivos = $pdo->prepare("DELETE FROM venta_archivos WHERE venta_id = ?");
    $stmtDelArchivos->execute([$idVenta]);

    // L-7: sus equipos salen del padrón (si no, seguirían generando tickets y bloquearían volver a
    // registrar esas series). Las órdenes que los atendieron conservan la serie como texto (L-29).
    $stmtEqVenta = $pdo->prepare("SELECT id FROM padron_equipos WHERE venta_id = ? AND origen = 'Venta propia'");
    $stmtEqVenta->execute([$idVenta]);
    $equiposVenta = array_map('intval', $stmtEqVenta->fetchAll(PDO::FETCH_COLUMN));
    if ($equiposVenta) {
        $marcas = implode(',', array_fill(0, count($equiposVenta), '?'));
        try { $pdo->prepare("UPDATE incidencia_equipos SET padron_id = NULL WHERE padron_id IN ($marcas)")->execute($equiposVenta); } catch (PDOException $e) { /* sin D-41 */ }
        $pdo->prepare("DELETE FROM padron_equipos WHERE id IN ($marcas)")->execute($equiposVenta);
        $ventaAnterior['equipos_padron_quitados'] = count($equiposVenta);
    }

    $stmtDelDetalles = $pdo->prepare("DELETE FROM venta_detalles WHERE venta_id = ?");
    $stmtDelDetalles->execute([$idVenta]);

    // Sin partidas, la conciliación regresa al inventario lo que la venta había descontado
    if (invTablasDisponibles($pdo)) {
        invSincronizarVenta($pdo, (int)$idVenta, $ventaAnterior['folio'] ?? null);
    }

    // ==========================================
    // 3. Borrar el registro "Padre" (La venta principal)
    // ==========================================
    $stmtDelete = $pdo->prepare("DELETE FROM ventas WHERE id = ?");
    $stmtDelete->execute([$idVenta]);

    // Venta que vino de una cotización: el equipo vuelve a "por registrar" y la
    // cotización deja de estar convertida (se puede registrar de nuevo)
    require_once __DIR__ . '/lib/cotizaciones_helpers.php';
    $cotizacionesReabiertas = cotLiberarVentaEliminada($pdo, $idVenta, $ventaAnterior['folio'] ?? null);

    // Sus cuentas por cobrar se conservan (con el folio como texto)
    require_once __DIR__ . '/lib/finanzas_helpers.php';
    if (finDisponible($pdo)) {
        $pdo->prepare("UPDATE cuentas_cobrar SET origen_id = NULL WHERE origen_tipo = 'venta' AND origen_id = ?")->execute([$idVenta]);
    }

    // Si todo salió bien, confirmamos la transacción
    $pdo->commit();

    foreach ($archivos as $archivo) {
        if (!empty($archivo['ruta_archivo']) && is_file($archivo['ruta_archivo'])) {
            @unlink($archivo['ruta_archivo']);
        }
    }

    registrarAuditoria('ventas', (int)$idVenta, 'DELETE', $ventaAnterior, null, $ventaAnterior['folio'] ?? null);

    echo json_encode(['exito' => true, 'mensaje' => 'Venta eliminada por completo.'
        . ($cotizacionesReabiertas ? ' Su cotización volvió a "aprobada" con el equipo por registrar.' : '')], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("Error SQL al eliminar venta: " . $e->getMessage());
    echo json_encode(['exito' => false, 'mensaje' => 'Error de Base de Datos: ' . mensajeErrorPublico($e)]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['exito' => false, 'mensaje' => mensajeErrorPublico($e)]);
}
?>