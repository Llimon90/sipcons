<?php
// Elimina una orden de servicio (solo Administrador o Soporte, RP-14).
//
// Todo ocurre en UNA transacción sobre $pdo, con la orden bloqueada (H-12):
// devolver al inventario lo consumido, desligar cotizaciones, gastos y cuentas por
// cobrar, borrar los registros de adjuntos y la orden, y dejar el historial. Si algo
// no se puede hacer (por ejemplo, una refacción que ahora es "servicio" y ya no lleva
// existencias), no se elimina nada y se explica por qué. Antes el borrado y la
// devolución iban en conexiones distintas: la orden desaparecía aunque las
// refacciones no regresaran, y se respondía éxito.
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../auth/permisos.php';
require_once __DIR__ . '/../auth/audit.php';
require_once __DIR__ . '/lib/inventario_helpers.php';
header('Content-Type: application/json');
ini_set('display_errors', '0');

function responderEliminar(int $http, array $cuerpo): void {
    global $pdo;
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code($http);
    echo json_encode($cuerpo, JSON_UNESCAPED_UNICODE);
    exit;
}

// Chequeo de rol fijo (no depende de permisos_rol), igual que requireGestionPrivilegios.
if (!esAdministracion()) {
    responderEliminar(403, ['success' => false, 'error' => 'Solo un administrador puede eliminar órdenes de servicio']);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    responderEliminar(405, ['success' => false, 'error' => 'Método no permitido']);
}

$data = json_decode(file_get_contents('php://input'), true) ?: [];
$id = (int)($data['id'] ?? 0);
if ($id <= 0) {
    responderEliminar(400, ['success' => false, 'error' => 'ID de orden de servicio inválido']);
}

try {
    $pdo->beginTransaction();

    $st = $pdo->prepare('SELECT * FROM incidencias WHERE id = ? FOR UPDATE');
    $st->execute([$id]);
    $anterior = $st->fetch(PDO::FETCH_ASSOC);
    if (!$anterior) {
        responderEliminar(404, ['success' => false, 'error' => 'Orden de servicio no encontrada']);
    }
    $folio = $anterior['numero_incidente'] ?? null;

    // H-08 · D-55: una orden que se está cobrando (directo, por su cotización o por la venta de su
    // cotización) no se elimina: desligar la cuenta dejaría volver a cobrar el mismo trabajo.
    require_once __DIR__ . '/lib/finanzas_helpers.php';
    if (finDisponible($pdo)) {
        $st = $pdo->prepare("SELECT k.folio FROM cuentas_cobrar k WHERE k.estatus <> 'cancelada' AND (
                                 (k.origen_tipo = 'incidencia' AND k.origen_id = ?)
                              OR (k.origen_tipo = 'cotizacion' AND k.origen_id IN (SELECT id FROM cotizaciones WHERE incidencia_id = ?))
                              OR (k.origen_tipo = 'venta' AND k.origen_id IN (SELECT cv.venta_id FROM cotizacion_ventas cv JOIN cotizaciones c ON c.id = cv.cotizacion_id
                                                                             WHERE c.incidencia_id = ? AND cv.venta_id IS NOT NULL)))
                             LIMIT 1");
        $st->execute([$id, $id, $id]);
        if ($cuenta = $st->fetchColumn()) {
            responderEliminar(409, ['success' => false, 'error' => "Esta orden se está cobrando con la cuenta $cuenta. Cancela esa cuenta en Finanzas antes de eliminar la orden."]);
        }
    }

    // Estado completo antes de borrar: es lo único que queda en el historial.
    $st = $pdo->prepare('SELECT ruta_archivo FROM archivos_incidencias WHERE incidencia_id = ? FOR UPDATE');
    $st->execute([$id]);
    $anterior['archivos'] = $st->fetchAll(PDO::FETCH_COLUMN);

    // 1. Las cotizaciones, gastos y cuentas por cobrar se conservan, ya sin la liga
    //    (antes que el inventario: orden de bloqueo único orden -> cotización -> artículos)
    require_once __DIR__ . '/lib/cotizaciones_helpers.php';
    if (cotDisponible($pdo)) {
        $pdo->prepare('UPDATE cotizaciones SET incidencia_id = NULL WHERE incidencia_id = ?')->execute([$id]);
    }
    require_once __DIR__ . '/lib/finanzas_helpers.php';
    if (finDisponible($pdo)) {
        $pdo->prepare('UPDATE gastos SET incidencia_id = NULL WHERE incidencia_id = ?')->execute([$id]);
        $pdo->prepare("UPDATE cuentas_cobrar SET origen_id = NULL WHERE origen_tipo = 'incidencia' AND origen_id = ?")->execute([$id]);
    }

    // 2. Las refacciones registradas en la orden regresan al almacén
    if (invTablasDisponibles($pdo)) {
        try {
            invRevertirIncidencia($pdo, $id, $folio);
        } catch (InventarioException $e) {
            responderEliminar(409, ['success' => false, 'motivo' => 'inventario',
                'error' => 'No se eliminó la orden de servicio: no se pudieron regresar sus refacciones al inventario. ' . $e->getMessage()
                    . ' Revisa ese artículo en Inventario (por ejemplo, si se cambió a servicio) e intenta de nuevo.']);
        }
    }

    // 3. Hijos primero (llaves foráneas). Los archivos físicos en uploads/ NO se
    //    borran: su nombre queda registrado en el historial.
    invQuitarConceptosIncidencia($pdo, $id);  // D-23: servicios y equipos de reemplazo del padrón
    $pdo->prepare('DELETE FROM archivos_incidencias WHERE incidencia_id = ?')->execute([$id]);
    $pdo->prepare('DELETE FROM incidencias WHERE id = ?')->execute([$id]);

    registrarAuditoria('incidencias', $id, 'DELETE', $anterior, null, $folio);
    $pdo->commit();
    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    error_log('Error al eliminar orden de servicio ' . $id . ': ' . $e->getMessage());
    responderEliminar(500, ['success' => false, 'error' => 'No se pudo eliminar la orden de servicio']);
}
