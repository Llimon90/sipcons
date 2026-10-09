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

// ---------------------------------------------------------------------
// Archivos adjuntos de incidencias (registro y edición)
// ---------------------------------------------------------------------

// Solo estos tipos se aceptan: evita que se suba un archivo ejecutable
// (p. ej. .php) a la carpeta uploads.
const EXTENSIONES_ADJUNTOS = [
    'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'heic', 'heif',
    'pdf',
    'mp4', 'webm', 'ogg', 'mov', 'm4v', '3gp',
    'mp3', 'wav', 'm4a', 'aac', 'oga',
    'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv',
    'zip', 'rar', '7z',
];

/**
 * Revisa los archivos recibidos en $_FILES[$campo] sin moverlos todavía.
 * Devuelve los válidos y los rechazados agrupados por motivo.
 */
function revisarArchivosSubidos(string $campo = 'archivos'): array {
    $revision = ['validos' => [], 'muy_grandes' => [], 'con_falla' => [], 'no_permitidos' => []];
    if (empty($_FILES[$campo]['name']) || !is_array($_FILES[$campo]['name'])) return $revision;

    foreach ($_FILES[$campo]['name'] as $i => $nombre) {
        $error = $_FILES[$campo]['error'][$i];
        if ($error === UPLOAD_ERR_NO_FILE) continue;
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            $revision['muy_grandes'][] = $nombre;
        } elseif ($error !== UPLOAD_ERR_OK) {
            $revision['con_falla'][] = $nombre;
        } elseif (!in_array(strtolower(pathinfo($nombre, PATHINFO_EXTENSION)), EXTENSIONES_ADJUNTOS, true)) {
            $revision['no_permitidos'][] = $nombre;
        } else {
            $revision['validos'][] = ['nombre' => $nombre, 'tmp' => $_FILES[$campo]['tmp_name'][$i]];
        }
    }
    return $revision;
}

/**
 * Si algún archivo no se puede aceptar, devuelve [http, motivo, mensaje]
 * con un texto sencillo para el usuario; si todo está bien, null.
 */
function rechazoDeArchivos(array $revision, array $limites): ?array {
    if ($revision['muy_grandes']) {
        $lista = $revision['muy_grandes'];
        return [413, 'archivo_muy_grande',
            (count($lista) === 1 ? "El archivo \"{$lista[0]}\" pesa demasiado" : 'Estos archivos pesan demasiado: ' . implode(', ', $lista)) .
            ($limites['archivo_mb'] ? " (máximo {$limites['archivo_mb']} MB por archivo)" : '') .
            '. No se guardó ningún cambio. Quítalo o redúcelo e intenta de nuevo.'];
    }
    if ($revision['no_permitidos']) {
        return [415, 'archivo_no_permitido',
            'No se permite este tipo de archivo: ' . implode(', ', $revision['no_permitidos']) .
            '. Puedes adjuntar imágenes, PDF, videos, audios, documentos de Office, texto o ZIP/RAR. No se guardó ningún cambio.'];
    }
    if ($revision['con_falla']) {
        return [400, 'archivo_incompleto',
            'No se pudo recibir completo: ' . implode(', ', $revision['con_falla']) . '. No se guardó ningún cambio. Intenta de nuevo.'];
    }
    return null;
}

/**
 * Mueve los archivos válidos a uploads/ con un nombre único y los registra
 * en archivos_incidencias (dentro de la transacción de quien llama).
 * Agrega cada ruta movida a $movidos para poder borrarla si algo falla
 * después. Si un archivo no se puede mover lanza RuntimeException con su
 * nombre original. Devuelve los nombres originales guardados.
 */
function guardarArchivosIncidencia(PDO $pdo, int $idIncidencia, array $validos, array &$movidos): array {
    if (!$validos) return [];
    $uploadDir = __DIR__ . '/../../uploads/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

    $insert = $pdo->prepare('INSERT INTO archivos_incidencias (incidencia_id, ruta_archivo) VALUES (?, ?)');
    $guardados = [];
    foreach ($validos as $archivo) {
        // Nombre único: antes dos archivos con el mismo nombre (p. ej. IMG_0001.jpg
        // en dos incidencias) se sobrescribían entre sí.
        $base = preg_replace('/[^A-Za-z0-9._-]+/', '_', basename($archivo['nombre']));
        $nombreFinal = 'inc' . $idIncidencia . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '_' . $base;
        if (!move_uploaded_file($archivo['tmp'], $uploadDir . $nombreFinal)) {
            throw new RuntimeException($archivo['nombre']);
        }
        $movidos[] = $uploadDir . $nombreFinal;
        // Misma forma de ruta que ya usa el resto del sistema
        $insert->execute([$idIncidencia, '../uploads/' . $nombreFinal]);
        $guardados[] = $archivo['nombre'];
    }
    return $guardados;
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
