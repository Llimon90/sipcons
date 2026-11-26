<?php
// Elimina un adjunto de una orden de servicio o de una venta (registro y archivo).
//
// Seguridad (auditorias/2026-11-10-ciclo-vida-triage.md, H-21 y H-14):
//  - La ruta física SIEMPRE sale de la base de datos, nunca de lo que manda el
//    navegador, y debe quedar dentro de uploads/e<ID>/ de la empresa en sesión.
//  - Adjuntos de órdenes exigen el permiso "incidencias"; de ventas, "ventas".
//  - Los errores no revelan rutas del servidor.
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../auth/permisos.php';
requirePermisoAlguno('incidencias', 'ventas');
require_once __DIR__ . '/../auth/audit.php';
require_once __DIR__ . '/lib/incidencias_helpers.php';

header('Content-Type: application/json');
ini_set('display_errors', '0');

function responderArchivo(int $http, array $cuerpo): void {
    global $pdo;
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code($http);
    echo json_encode($cuerpo + ['exito' => !empty($cuerpo['success'])], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Ubica el archivo de una ruta guardada en la base (relativa a backend/, como
 * "../uploads/e7/..."). La ruta se normaliza sin depender de que exista y debe
 * quedar en la carpeta del módulo dentro de uploads/e<ID>/ de la empresa en sesión:
 * ventas -> uploads/e<ID>/ventas/...; incidencias -> uploads/e<ID>/<archivo> (raíz).
 * Devuelve ['existe', ruta absoluta], ['falta', null] (ruta válida pero el archivo o
 * su carpeta ya no están) o ['fuera', null] (apunta a otro lugar: no se toca).
 */
function ubicarArchivo(string $rutaGuardada, string $modulo): array {
    $partes = [];
    foreach (explode('/', str_replace(chr(92), '/', $rutaGuardada)) as $p) {
        if ($p === '' || $p === '.') continue;
        if ($p === '..') { if (!$partes) continue; array_pop($partes); continue; }  // los "../" iniciales son relativos a backend/
        if (strpos($p, chr(0)) !== false) return ['fuera', null];
        $partes[] = $p;
    }
    $relativa = implode('/', $partes);
    $prefijo = carpetaEmpresa('uploads') . '/';
    if (strpos($relativa, $prefijo) !== 0) return ['fuera', null];
    $resto = substr($relativa, strlen($prefijo));
    $valida = $modulo === 'ventas' ? strpos($resto, 'ventas/') === 0 && substr_count($resto, '/') >= 1
                                   : $resto !== '' && strpos($resto, '/') === false;
    if (!$valida) return ['fuera', null];

    $archivo = __DIR__ . '/../' . $relativa;
    if (is_link($archivo)) return ['fuera', null];
    if (!is_file($archivo)) return ['falta', null];
    $base = realpath(__DIR__ . '/../' . carpetaEmpresa('uploads'));
    $real = realpath($archivo);
    if (!$base || !$real || strpos($real, $base . DIRECTORY_SEPARATOR) !== 0) return ['fuera', null];
    // La carpeta del módulo se revalida sobre la ruta ya resuelta (enlaces de carpeta)
    $subReal = str_replace(chr(92), '/', substr($real, strlen($base) + 1));
    $okReal = $modulo === 'ventas' ? strpos($subReal, 'ventas/') === 0 : strpos($subReal, '/') === false;
    if (!$okReal) return ['fuera', null];
    return ['existe', $real];
}

// Borrar es una acción que cambia datos: solo POST (además, el modo solo lectura
// de la cuenta deja pasar GET/HEAD).
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    responderArchivo(405, ['success' => false, 'error' => 'Solicitud no válida.']);
}

$esJson = stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false;
$data = $esJson ? (json_decode(file_get_contents('php://input'), true) ?: []) : $_POST;

if (($data['modulo'] ?? '') === 'ventas') {
    $modulo = 'ventas';
    if (!tienePermiso('ventas')) responderArchivo(403, ['success' => false, 'error' => 'Tu rol no tiene acceso a las ventas.']);
    $idArchivo = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$idArchivo) responderArchivo(400, ['success' => false, 'error' => 'Datos incompletos de la venta.']);
} elseif (!empty($data['id_incidencia']) && !empty($data['url_archivo'])) {
    $modulo = 'incidencias';
    if (!tienePermiso('incidencias')) responderArchivo(403, ['success' => false, 'error' => 'Tu rol no tiene acceso a las órdenes de servicio.']);
    $idOrden = filter_var($data['id_incidencia'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $nombreArchivo = basename(str_replace('\\', '/', trim((string)$data['url_archivo'])));
    if (!$idOrden || $nombreArchivo === '') responderArchivo(400, ['success' => false, 'error' => 'Datos incompletos de la orden de servicio.']);
} else {
    responderArchivo(400, ['success' => false, 'error' => 'Solicitud no válida.']);
}

try {
    $pdo->beginTransaction();

    if ($modulo === 'incidencias') {
        // Bloquea la orden primero (mismo orden que eliminar-incidencia.php)
        $orden = ordenVisiblePorId($pdo, $idOrden, true);  // D-9: ajena = no existe
        if (!$orden) responderArchivo(404, ['success' => false, 'error' => 'La orden de servicio no existe.']);
        $folio = $orden['numero_incidente'] ?: null;
        // El adjunto se busca por su nombre exacto entre los de esa orden (nada de LIKE)
        $st = $pdo->prepare('SELECT id, ruta_archivo FROM archivos_incidencias WHERE incidencia_id = ? FOR UPDATE');
        $st->execute([$idOrden]);
        $fila = null;
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $f) {
            if (basename(str_replace('\\', '/', $f['ruta_archivo'])) === $nombreArchivo) { $fila = $f; break; }
        }
        if (!$fila) responderArchivo(404, ['success' => false, 'error' => 'El archivo ya no está registrado en esta orden de servicio.']);
        $registroAuditoria = $idOrden;
        $rutaGuardada = $fila['ruta_archivo'];
        $pdo->prepare('DELETE FROM archivos_incidencias WHERE id = ?')->execute([$fila['id']]);
    } else {
        $st = $pdo->prepare('SELECT va.id, va.venta_id, va.ruta_archivo, va.nombre_archivo, v.folio
                             FROM venta_archivos va LEFT JOIN ventas v ON v.id = va.venta_id WHERE va.id = ? FOR UPDATE');
        $st->execute([$idArchivo]);
        $fila = $st->fetch(PDO::FETCH_ASSOC);
        if (!$fila) responderArchivo(404, ['success' => false, 'error' => 'El archivo ya no está registrado en esta venta.']);
        $registroAuditoria = (int)$fila['venta_id'];
        $folio = $fila['folio'] ?: null;
        $nombreArchivo = $fila['nombre_archivo'] ?: basename((string)$fila['ruta_archivo']);
        $rutaGuardada = (string)$fila['ruta_archivo'];
        $pdo->prepare('DELETE FROM venta_archivos WHERE id = ?')->execute([$idArchivo]);
    }

    [$estado, $rutaFisica] = ubicarArchivo($rutaGuardada, $modulo);
    if ($estado === 'fuera') {
        error_log("eliminar_archivo: ruta guardada fuera de la empresa ($modulo, registro $registroAuditoria)");
        responderArchivo(404, ['success' => false, 'error' => 'El archivo no se encontró. Recarga la página e intenta de nuevo.']);
    }

    // Primero se confirma en la base y después se borra del disco: si el borrado
    // físico falla, solo queda un archivo sin uso (nunca un registro sin archivo).
    $pdo->commit();
    if ($estado === 'existe' && !@unlink($rutaFisica)) {
        error_log("eliminar_archivo: se quitó el registro pero no se pudo borrar $rutaFisica");
    }
    registrarAuditoria($modulo, $registroAuditoria, 'ARCHIVO_ELIMINADO',
        ['archivo' => $nombreArchivo] + ($estado === 'falta' ? ['nota' => 'el archivo ya no existía en el servidor'] : []), null, $folio);
    responderArchivo(200, ['success' => true, 'message' => 'Archivo eliminado.']);
} catch (Throwable $e) {
    error_log('eliminar_archivo: ' . $e->getMessage());
    responderArchivo(500, ['success' => false, 'error' => 'No se pudo eliminar el archivo. Intenta de nuevo.']);
}
