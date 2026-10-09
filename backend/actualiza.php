<?php
// Guarda los cambios de una incidencia (detalle.html).
//
// Garantías para quien guarda:
//  - Siempre responde JSON con { success: true } o { success: false, motivo, error }.
//    "error" es un mensaje sencillo que el usuario puede leer tal cual.
//  - O se guarda todo (campos y archivos) o no se guarda nada (transacción).
//  - Solo responde éxito después de volver a leer la incidencia de la base de
//    datos y comprobar que quedó con lo que se envió.
//  - Si alguien más (u otra pestaña/equipo del mismo usuario) guardó cambios
//    desde que se abrió la página, no los sobrescribe sin confirmación (409).
// Cada intento que no se completa queda en storage/logs/guardados-AAAA-MM.log.
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../auth/audit.php';
require_once __DIR__ . '/../config/guardados_log.php';
require_once __DIR__ . '/lib/incidencias_helpers.php';

// Un aviso de PHP impreso en la respuesta rompería el JSON en el navegador
ini_set('display_errors', '0');

$idIncidencia = null;
$archivosMovidos = [];

function responder(int $http, array $cuerpo): void {
    http_response_code($http);
    echo json_encode($cuerpo, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Responde un guardado que no se completó y lo anota en la bitácora. */
function rechazar(int $http, string $motivo, string $mensaje, array $extra = [], string $detalleLog = ''): void {
    global $idIncidencia, $archivosMovidos, $pdo;
    if ($pdo->inTransaction()) $pdo->rollBack();
    foreach ($archivosMovidos as $ruta) @unlink($ruta);
    registrarFalloGuardado($motivo, $idIncidencia !== null ? (string)$idIncidencia : null, $detalleLog ?: $mensaje);
    responder($http, ['success' => false, 'motivo' => $motivo, 'error' => $mensaje] + $extra);
}

/** Quién guardó por última vez la incidencia y cuándo (para explicar un conflicto). */
function ultimoCambio(PDO $pdo, int $id): array {
    try {
        $st = $pdo->prepare("SELECT usuario_id, usuario_nombre, creado_en FROM auditoria
            WHERE tabla = 'incidencias' AND registro_id = ? AND accion IN ('UPDATE', 'CREATE', 'INSERT')
            ORDER BY id DESC LIMIT 1");
        $st->execute([(string)$id]);
        $fila = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $fila = false;
    }
    if (!$fila) return ['modificado_por' => null, 'modificado_en' => null, 'modificado_por_mi' => false];
    return [
        'modificado_por' => $fila['usuario_nombre'],
        'modificado_en' => $fila['creado_en'],
        'modificado_por_mi' => (string)$fila['usuario_id'] === (string)($_SESSION['user_id'] ?? ''),
    ];
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        responder(405, ['success' => false, 'motivo' => 'metodo', 'error' => 'Solicitud no válida.']);
    }

    $limites = limitesSubida();

    // Si el envío supera post_max_size, PHP descarta TODO el formulario
    // (campos y archivos) sin avisar: antes esto respondía "éxito".
    $tamanoEnvio = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if (empty($_POST) && empty($_FILES) && $tamanoEnvio > 0) {
        rechazar(413, 'envio_muy_grande',
            'Los archivos que adjuntaste pesan demasiado' .
            ($limites['total_mb'] ? " (el máximo es {$limites['total_mb']} MB en total)" : '') .
            '. No se guardó ningún cambio. Quita o reduce los archivos e intenta de nuevo.',
            ['limites' => $limites],
            'Envío de ' . round($tamanoEnvio / 1048576, 1) . ' MB descartado por post_max_size');
    }

    $idIncidencia = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$idIncidencia) {
        $idIncidencia = null;
        rechazar(400, 'id_invalido', 'No se pudo identificar la incidencia. Recarga la página e intenta de nuevo.');
    }

    // Campos que el formulario siempre envía; si faltan, el envío llegó incompleto
    foreach (['cliente', 'contacto', 'fecha', 'estatus', 'falla'] as $campo) {
        if (!array_key_exists($campo, $_POST)) {
            rechazar(400, 'envio_incompleto', 'Los datos llegaron incompletos al servidor. Recarga la página e intenta de nuevo.',
                [], "Falta el campo $campo");
        }
    }

    $valores = [
        'reporte_cliente' => (string)($_POST['reporte_cliente'] ?? ''),
        'cliente'   => trim((string)$_POST['cliente']),
        'contacto'  => trim((string)$_POST['contacto']),
        'sucursal'  => (string)($_POST['sucursal'] ?? ''),
        'categoria' => (string)($_POST['categoria'] ?? ''),
        'fecha'     => (string)$_POST['fecha'],
        'tecnico'   => (string)($_POST['tecnico'] ?? ''),
        'estatus'   => (string)$_POST['estatus'],
        'falla'     => (string)$_POST['falla'],
        'accion'    => (string)($_POST['accion'] ?? ''),
        'notas'     => (string)($_POST['notas'] ?? ''),
    ];

    if ($valores['cliente'] === '' || $valores['contacto'] === '' || $valores['fecha'] === '' || trim($valores['falla']) === '') {
        rechazar(400, 'faltan_datos', 'Faltan datos obligatorios: cliente, quién reporta, fecha y falla.');
    }

    // El rol Técnico no puede cerrar incidencias con/sin factura
    if (($_SESSION['rol'] ?? '') === 'Técnico' && in_array($valores['estatus'], ['Cerrado con factura', 'Cerrado sin factura'], true)) {
        rechazar(403, 'sin_permiso', 'Tu usuario no tiene permiso para cerrar incidencias con o sin factura.');
    }

    // Archivos: se revisan ANTES de tocar la base. Si alguno no se puede
    // aceptar, no se guarda nada y se dice cuál fue.
    $revisionArchivos = revisarArchivosSubidos('archivos');
    if ($rechazo = rechazoDeArchivos($revisionArchivos, $limites)) {
        rechazar($rechazo[0], $rechazo[1], $rechazo[2], ['limites' => $limites]);
    }

    $pdo->beginTransaction();

    // Bloquea la fila hasta terminar: dos guardados simultáneos no se pisan
    $st = $pdo->prepare('SELECT * FROM incidencias WHERE id = ? FOR UPDATE');
    $st->execute([$idIncidencia]);
    $anterior = $st->fetch(PDO::FETCH_ASSOC);
    if (!$anterior) {
        rechazar(404, 'no_existe', 'Esta incidencia ya no existe; es posible que alguien la haya eliminado.');
    }

    // ¿Cambió desde que se abrió la página? (otra persona, u otra pestaña/equipo propio)
    $versionCliente = (string)($_POST['version'] ?? '');
    $forzar = ($_POST['forzar'] ?? '') === '1';
    if ($versionCliente !== '' && !$forzar && versionIncidencia($anterior) !== $versionCliente) {
        $quien = ultimoCambio($pdo, $idIncidencia);
        rechazar(409, 'conflicto',
            'La incidencia se modificó en otro lugar después de que la abriste.',
            $quien + ['actual' => incidenciaParaFrontend($pdo, $anterior)],
            'Modificada por ' . ($quien['modificado_por'] ?? '?') . ' el ' . ($quien['modificado_en'] ?? '?'));
    }
    if ($forzar) {
        registrarFalloGuardado('conflicto_sobrescrito', (string)$idIncidencia, 'El usuario decidió guardar encima de cambios ajenos');
    }

    $tieneSerie = array_key_exists('numero_serie', $anterior);
    if ($tieneSerie) {
        $serie = trim((string)($_POST['numero_serie'] ?? ''));
        $valores['numero_serie'] = $serie !== '' ? $serie : null;
    }

    $sets = implode(', ', array_map(fn($c) => "`$c` = ?", array_keys($valores)));
    $st = $pdo->prepare("UPDATE incidencias SET $sets WHERE id = ?");
    $st->execute(array_merge(array_values($valores), [$idIncidencia]));

    try {
        $archivosAgregados = guardarArchivosIncidencia($pdo, $idIncidencia, $revisionArchivos['validos'], $archivosMovidos);
    } catch (RuntimeException $e) {
        rechazar(500, 'archivo_no_guardado',
            "No se pudo guardar el archivo \"{$e->getMessage()}\" en el servidor. No se guardó ningún cambio. Intenta de nuevo.");
    }

    // Comprobación: se vuelve a leer y se compara con lo enviado
    $st = $pdo->prepare('SELECT * FROM incidencias WHERE id = ?');
    $st->execute([$idIncidencia]);
    $guardada = $st->fetch(PDO::FETCH_ASSOC);
    $distintos = camposQueNoCoinciden($valores, $guardada ?: []);
    if ($distintos) {
        rechazar(500, 'verificacion',
            'No se pudo guardar correctamente: ' . nombresCamposIncidencia($distintos) .
            '. Es posible que el texto sea demasiado largo o tenga caracteres especiales no admitidos (por ejemplo, emojis). No se guardó ningún cambio.',
            [], 'Campos que no coinciden tras guardar: ' . implode(',', $distintos));
    }

    $datosNuevos = ['id' => $idIncidencia] + $valores;
    if ($archivosAgregados) $datosNuevos['archivos_agregados'] = $archivosAgregados;
    registrarAuditoria('incidencias', $idIncidencia, 'UPDATE', $anterior, $datosNuevos, $anterior['numero_incidente'] ?? null);

    // La respuesta se arma antes de confirmar: si algo fallara después del
    // commit, se le diría al usuario que no se guardó cuando sí se guardó.
    $respuesta = [
        'success' => true,
        'guardado_en' => date('Y-m-d H:i:s'),
        'archivos_agregados' => $archivosAgregados,
        'incidencia' => incidenciaParaFrontend($pdo, $guardada),
    ];

    $pdo->commit();
    responder(200, $respuesta);
} catch (Throwable $e) {
    error_log('actualiza.php: ' . $e->getMessage());
    rechazar(500, 'error_servidor',
        'Ocurrió un problema en el servidor y no se guardaron los cambios. Intenta de nuevo en un momento.',
        [], get_class($e) . ': ' . $e->getMessage());
}
