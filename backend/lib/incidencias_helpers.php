<?php
// Utilidades compartidas por detalle.php y actualiza.php.

/**
 * Huella del contenido de una incidencia tal como está en la base de datos.
 *
 * detalle.php la entrega al abrir la incidencia y actualiza.php la compara
 * antes de guardar: si no coincide, alguien (otra persona, u otra pestaña o
 * equipo del mismo usuario) guardó cambios mientras la página estaba
 * abierta, y guardar encima los borraría. Los valores se pasan a texto para
 * que mysqli y PDO, que devuelven tipos distintos, den la misma huella.
 */
function versionIncidencia(array $fila): string {
    $normal = [];
    foreach ($fila as $columna => $valor) {
        $normal[$columna] = $valor === null ? null : (string)$valor;
    }
    ksort($normal);
    return hash('sha256', json_encode($normal, JSON_UNESCAPED_UNICODE));
}

/** La incidencia como la usa el frontend (técnicos como lista, archivos, versión). */
function incidenciaParaFrontend(PDO $pdo, array $fila): array {
    $datos = $fila;
    $datos['version'] = versionIncidencia($fila);
    $datos['tecnico'] = !empty($fila['tecnico']) ? explode('/', $fila['tecnico']) : [];
    $st = $pdo->prepare('SELECT ruta_archivo FROM archivos_incidencias WHERE incidencia_id = ?');
    $st->execute([$fila['id']]);
    $datos['archivos'] = $st->fetchAll(PDO::FETCH_COLUMN);
    return $datos;
}

/** Convierte un valor de php.ini como "8M" o "1G" a bytes. */
function bytesDeIni(string $valor): int {
    $valor = trim($valor);
    if ($valor === '') return 0;
    $numero = (float)$valor;
    switch (strtolower(substr($valor, -1))) {
        case 'g': $numero *= 1024;
        // no break
        case 'm': $numero *= 1024;
        // no break
        case 'k': $numero *= 1024;
    }
    return (int)$numero;
}

/**
 * Límites de subida del servidor en MB (por archivo y por guardado completo),
 * para avisar antes de enviar y para explicar el rechazo.
 */
function limitesSubida(): array {
    $porArchivo = bytesDeIni((string)ini_get('upload_max_filesize'));
    $total = bytesDeIni((string)ini_get('post_max_size'));
    if ($total > 0 && ($porArchivo === 0 || $porArchivo > $total)) $porArchivo = $total;
    return [
        'archivo_mb' => $porArchivo > 0 ? round($porArchivo / 1048576, 1) : null,
        'total_mb'   => $total > 0 ? round($total / 1048576, 1) : null,
        'max_archivos' => (int)ini_get('max_file_uploads') ?: null,
    ];
}
