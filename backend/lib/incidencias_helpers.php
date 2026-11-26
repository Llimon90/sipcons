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

// Tipos aceptados: EXTENSIONES_ADJUNTOS (lib/subidas.php), la misma lista
// para incidencias y ventas.
require_once __DIR__ . '/subidas.php';

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
        } elseif (!extensionAdjuntoPermitida($nombre)) {
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
 * Mueve los archivos válidos a la carpeta de la empresa (uploads/e<ID>/) con un nombre único y los registra
 * en archivos_incidencias (dentro de la transacción de quien llama).
 * Agrega cada ruta movida a $movidos para poder borrarla si algo falla
 * después. Si un archivo no se puede mover lanza RuntimeException con su
 * nombre original. Devuelve los nombres originales guardados.
 */
function guardarArchivosIncidencia(PDO $pdo, int $idIncidencia, array $validos, array &$movidos): array {
    if (!$validos) return [];
    $uploadDir = __DIR__ . '/../../' . carpetaEmpresa('uploads') . '/';
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
        $insert->execute([$idIncidencia, '../' . carpetaEmpresa('uploads') . '/' . $nombreFinal]);
        $guardados[] = $archivo['nombre'];
    }
    return $guardados;
}

// ---------------------------------------------------------------------
// Verificación después de escribir (alta y edición)
// ---------------------------------------------------------------------

/**
 * Compara lo enviado con lo que quedó en la base de datos y devuelve los
 * campos que no coinciden (vacío = se guardó tal cual). Los espacios al
 * final no cuentan (algunas columnas los recortan) y la fecha se compara
 * solo como AAAA-MM-DD.
 */
function camposQueNoCoinciden(array $enviados, array $enBase): array {
    $distintos = [];
    foreach ($enviados as $campo => $valor) {
        $guardado = $enBase[$campo] ?? null;
        $a = rtrim($valor === null ? '' : (string)$valor);
        $b = rtrim($guardado === null ? '' : (string)$guardado);
        if ($campo === 'fecha') { $a = substr($a, 0, 10); $b = substr($b, 0, 10); }
        if ($a !== $b) $distintos[] = $campo;
    }
    return $distintos;
}

/** Nombres legibles de los campos de una incidencia (para mensajes al usuario). */
function nombresCamposIncidencia(array $campos): string {
    $nombres = [
        'reporte_cliente' => '# reporte del cliente', 'cliente' => 'cliente', 'contacto' => 'contacto',
        'sucursal' => 'sucursal', 'categoria' => 'categoría', 'fecha' => 'fecha', 'tecnico' => 'técnicos',
        'estatus' => 'estatus', 'motivo_estatus' => 'motivo del estatus', 'falla' => 'motivo del servicio', 'accion' => 'trabajo realizado', 'notas' => 'notas',
        'numero_serie' => 'número de serie', 'numero_incidente' => 'folio',
    ];
    return implode(', ', array_map(fn($c) => $nombres[$c] ?? $c, $campos));
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

// ---------------------------------------------------------------------
// D-9: el Técnico ve y edita solo las órdenes donde está asignado.
// Se usa en todos los endpoints que listan, abren o modifican órdenes o lo que
// cuelga de ellas (adjuntos, refacciones, cotizaciones). Los demás roles ven todas.
// ---------------------------------------------------------------------
require_once __DIR__ . '/estadisticas_helpers.php';  // separarTecnicos, tecnicoCoincide, sqlCoincideTecnico

function soloOrdenesPropias(): bool {
    return ($_SESSION['rol'] ?? '') === 'Técnico';
}

/** Nombre con el que el usuario en sesión aparece en incidencias.tecnico (alias o nombre). */
function identidadTecnicoSesion(PDO $pdo): string {
    static $cache = [];
    $uid = (int)($_SESSION['user_id'] ?? 0);
    $clave = $uid . '|' . (int)($_SESSION['empresa_id'] ?? 0) . '|' . ($_SESSION['nombre'] ?? '');
    if (isset($cache[$clave])) return $cache[$clave];
    $identidad = trim((string)($_SESSION['nombre'] ?? ''));
    try {
        $st = $pdo->prepare('SELECT nombre, alias_tecnico FROM usuarios WHERE id = ? AND empresa_id = ?');
        $st->execute([$uid, (int)($_SESSION['empresa_id'] ?? 0)]);
        if ($u = $st->fetch(PDO::FETCH_ASSOC)) {
            $identidad = trim((string)($u['alias_tecnico'] ?? '')) ?: trim((string)$u['nombre']);
        }
    } catch (Throwable $e) {
        // Sin la columna alias_tecnico (migración 008) se usa el nombre de la sesión
    }
    return $cache[$clave] = $identidad;
}

/** ¿El usuario en sesión puede ver o tocar una orden con este valor de "tecnico"? */
function ordenVisibleParaSesion(PDO $pdo, ?string $tecnicos): bool {
    if (!soloOrdenesPropias()) return true;
    $yo = identidadTecnicoSesion($pdo);
    return $yo !== '' && tecnicoCoincide($yo, (string)$tecnicos);
}

/**
 * Condición SQL (y sus parámetros) para filtrar órdenes del usuario en sesión.
 * Para los roles que ven todo devuelve ['1=1', []]. $columna: p. ej. 'tecnico' o 'i.tecnico'.
 */
function filtroOrdenesSesion(PDO $pdo, string $columna = 'tecnico'): array {
    if (!soloOrdenesPropias()) return ['1=1', []];
    $yo = identidadTecnicoSesion($pdo);
    if ($yo === '') return ['1=0', []];
    return [sqlCoincideTecnico($columna) . " LIKE ?", ['%/' . addcslashes($yo, '%_' . chr(92)) . '/%']];
}

/**
 * Deja solo las filas (con columna "tecnico") que el usuario en sesión puede ver.
 * El filtro SQL es un prefiltro (MySQL compara sin acentos: "Jose" = "José"); la
 * decisión final es esta comparación exacta en PHP.
 */
function filtrarOrdenesVisibles(PDO $pdo, array $filas): array {
    if (!soloOrdenesPropias()) return $filas;
    return array_values(array_filter($filas, fn($f) => ordenVisibleParaSesion($pdo, $f['tecnico'] ?? '')));
}

/** Lee una orden por id y la devuelve solo si el usuario en sesión puede verla (si no, null). */
function ordenVisiblePorId(PDO $pdo, int $id, bool $bloquear = false): ?array {
    $st = $pdo->prepare('SELECT * FROM incidencias WHERE id = ?' . ($bloquear ? ' FOR UPDATE' : ''));
    $st->execute([$id]);
    $fila = $st->fetch(PDO::FETCH_ASSOC);
    return $fila && ordenVisibleParaSesion($pdo, $fila['tecnico'] ?? '') ? $fila : null;
}

// ------------------------------------------------------------------ estatus
// Lista cerrada de estatus y sus condiciones (docs/REGLAS_NEGOCIO.md 0.1, D-1 a D-5, D-37).

const ESTATUS_ORDEN = ['Abierto', 'Asignado', 'En proceso', 'Pendiente', 'Completado',
                       'Cerrado sin factura', 'Cerrado con factura', 'Cancelado'];
/** Estatus que ya no son "activos" (D-4): salir de ellos es reabrir (D-3). */
const ESTATUS_FINALES = ['Cerrado sin factura', 'Cerrado con factura', 'Cancelado'];
/** Estatus que exigen un motivo (guardado en incidencias.motivo_estatus). */
const ESTATUS_CON_MOTIVO = ['Pendiente', 'Cancelado'];
/** Estatus que exigen al menos un técnico. */
const ESTATUS_CON_TECNICO = ['Asignado', 'En proceso'];

/** Forma oficial del estatus (sin distinguir mayúsculas ni espacios), o null si no está en la lista. */
function estatusOficial(?string $valor): ?string {
    $clave = mb_strtolower(preg_replace('/\s+/u', ' ', trim((string)$valor)));
    foreach (ESTATUS_ORDEN as $e) {
        if (mb_strtolower($e) === $clave) return $e;
    }
    return null;
}

/** Condición SQL de estatus activo (D-4), incluidas las programadas para después. */
function sqlEstatusActivo(string $columna = 'estatus'): string {
    $lista = implode(', ', array_map(fn($e) => "'$e'", ESTATUS_FINALES));
    return "($columna IS NULL OR $columna NOT IN ($lista))";
}

/** Columna de fecha de la misma tabla que $columna ('i.estatus' -> 'i.fecha'). */
function columnaFechaDe(string $columna): string {
    $punto = strrpos($columna, '.');
    return ($punto === false ? '' : substr($columna, 0, $punto + 1)) . 'fecha';
}

/**
 * Condición SQL de orden activa (D-4): una sola definición para todos los endpoints.
 * D-42: una orden con fecha futura está programada y no cuenta como activa hasta su día
 * (bandeja, contadores, alertas, atraso). MySQL ya usa la zona horaria de la empresa.
 */
function sqlOrdenActiva(string $columna = 'estatus'): string {
    $fecha = columnaFechaDe($columna);
    return '(' . sqlEstatusActivo($columna) . " AND ($fecha IS NULL OR $fecha <= CURDATE()))";
}

/** D-42: orden programada (estatus activo y fecha futura). */
/**
 * L-18: la fecha de reporte debe existir (2026-02-30 daba 500) y no estar a más de un año: una orden
 * con fecha en 2099 quedaría «programada» para siempre, fuera de la bandeja, del atraso y del SLA.
 */
function errorFechaOrden(string $fecha): ?string {
    $f = trim($fecha);  // H-10: la cadena completa (se guarda tal cual)
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $f, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) return 'La fecha no es válida.';
    if ($f > date('Y-m-d', strtotime('+1 year'))) return 'La fecha no puede estar a más de un año: para programar, elige una fecha dentro de los próximos 12 meses.';
    if ($f < '2000-01-01') return 'La fecha no es válida.';
    return null;
}

function sqlOrdenProgramada(string $columna = 'estatus'): string {
    // L-34: una captura posterior ya Completada con fecha futura no es «programada»
    return '(' . sqlEstatusActivo($columna) . " AND ($columna IS NULL OR $columna <> 'Completado') AND " . columnaFechaDe($columna) . ' > CURDATE())';
}

/** Orden activa que todavía espera trabajo del técnico (activa y no Completado; D-33). */
function sqlOrdenPorAtender(string $columna = 'estatus'): string {
    return '(' . sqlOrdenActiva($columna) . " AND ($columna IS NULL OR $columna <> 'Completado'))";
}

/** ¿La incidencia tiene la columna motivo_estatus (migración 010)? */
function incidenciasTieneMotivoEstatus(PDO $pdo): bool {
    static $existe = null;
    if ($existe === null) {
        try {
            $existe = (bool)$pdo->query("SHOW COLUMNS FROM incidencias LIKE 'motivo_estatus'")->fetch();
        } catch (Throwable $e) {
            $existe = false;
        }
    }
    return $existe;
}

/**
 * Aplica las reglas de estatus a un alta ($anterior = null) o a una edición.
 *  - $estatus: el que eligió el usuario. $tecnicos: los técnicos ya normalizados.
 *  - $motivo: motivo de Pendiente/Cancelado (null = no vino en el envío).
 *  - $motivoReapertura: obligatorio al salir de Cerrado o Cancelado.
 * Devuelve ['estatus' => ..., 'motivo_estatus' => ..., 'reapertura' => ?string]
 * o ['error' => mensaje, 'http' => 400|403].
 */
function reglasEstatusOrden(?array $anterior, string $estatus, string $tecnicos, ?string $motivo,
                            string $motivoReapertura, bool $puedeCerrar): array {
    $est = estatusOficial($estatus);
    if ($est === null) {
        return ['http' => 400, 'error' => 'Elige un estatus de la lista: ' . implode(', ', ESTATUS_ORDEN) . '.'];
    }
    $antes = $anterior !== null ? estatusOficial($anterior['estatus'] ?? '') : null;
    $conTecnico = trim($tecnicos) !== '';

    // D-5: el estatus sigue a la asignación
    if (in_array($est, ESTATUS_CON_TECNICO, true) && !$conTecnico) {
        if ($anterior !== null && $est === $antes) {
            $est = 'Abierto';  // se quitaron todos los técnicos de una orden en curso
        } else {
            return ['http' => 400, 'error' => "Para poner la orden en \"$est\" asígnale al menos un técnico."];
        }
    }
    if ($est === 'Abierto' && $conTecnico) $est = 'Asignado';

    $cambia = $est !== $antes;

    // Cerrar o cancelar (RP-12): solo se bloquea CAMBIAR a ese estatus
    if ($cambia && (in_array($est, ESTATUS_DE_CIERRE, true) || $est === 'Cancelado') && !$puedeCerrar) {
        return ['http' => 403, 'error' => $est === 'Cancelado'
            ? 'Tu usuario no tiene permiso para cancelar órdenes de servicio.'
            : 'Tu usuario no tiene permiso para cerrar órdenes de servicio con o sin factura.'];
    }

    // D-3: reabrir = salir de Cerrado o Cancelado hacia un estatus activo
    $reapertura = null;
    if ($antes !== null && in_array($antes, ESTATUS_FINALES, true) && !in_array($est, ESTATUS_FINALES, true)) {
        if (!$puedeCerrar) {
            return ['http' => 403, 'error' => 'Solo quien puede cerrar órdenes de servicio puede reabrirlas.'];
        }
        $reapertura = trim($motivoReapertura);
        if ($reapertura === '') {
            return ['http' => 400, 'error' => 'Para reabrir la orden de servicio escribe el motivo.'];
        }
        $reapertura = mb_substr($reapertura, 0, 500);
    }

    // Motivo de Pendiente o Cancelado (se borra al salir de esos estatus)
    $motivoFinal = null;
    if (in_array($est, ESTATUS_CON_MOTIVO, true)) {
        $motivoFinal = trim((string)$motivo);
        // Sin cambio de estatus y sin motivo en el envío, se conserva el que tenía
        if ($motivoFinal === '' && !$cambia && $motivo === null) $motivoFinal = trim((string)($anterior['motivo_estatus'] ?? ''));
        if ($motivoFinal === '') {
            return ['http' => 400, 'error' => $est === 'Pendiente'
                ? 'Para dejar la orden en Pendiente indica el motivo (refacción, aprobación de cotización, cliente o acceso, u otro).'
                : 'Para cancelar la orden de servicio escribe el motivo.'];
        }
        $motivoFinal = mb_substr($motivoFinal, 0, 255);
    }

    return ['estatus' => $est, 'motivo_estatus' => $motivoFinal, 'reapertura' => $reapertura];
}

// ------------------------------------------------------------------ cliente
/**
 * D-26: el cliente de una orden debe existir en el catálogo. Devuelve el nombre tal
 * como está en el catálogo (sin distinguir mayúsculas ni espacios), o null.
 */
function clienteDelCatalogo(PDO $pdo, string $cliente): ?string {
    $cliente = preg_replace('/\s+/u', ' ', trim($cliente));
    if ($cliente === '') return null;
    $st = $pdo->prepare('SELECT nombre FROM clientes WHERE LOWER(TRIM(nombre)) = LOWER(?) ORDER BY id LIMIT 1');
    $st->execute([$cliente]);
    $nombre = $st->fetchColumn();
    return $nombre === false ? null : trim((string)$nombre);
}

// ------------------------------------------------------------------ atraso
// D-18: una orden por atender está atrasada cuando han pasado MÁS días completos que
// la meta: días(fecha de reporte, hoy) > sla_dias. Un solo cálculo para Inicio,
// alertas y el panel del técnico. Completado no cuenta como atrasada (D-33).

/** Días naturales completos desde la fecha de reporte hasta hoy (0 si es futura). */
function diasDesdeReporte(?string $fecha): int {
    if (!$fecha || !fechaValidaYmd(substr($fecha, 0, 10))) return 0;
    $dias = (int)(new DateTime(substr($fecha, 0, 10)))->diff(new DateTime('today'))->format('%r%a');
    return max(0, $dias);
}

function ordenAtrasada(?string $fecha, ?string $estatus): bool {
    if (in_array($estatus, ESTATUS_FINALES, true) || $estatus === 'Completado') return false;
    return diasDesdeReporte($fecha) > slaDiasEmpresa();
}

/** Condición SQL equivalente a ordenAtrasada() (MySQL ya usa la zona de la empresa). */
function sqlOrdenAtrasada(string $columnaFecha = 'fecha', string $columnaEstatus = 'estatus'): string {
    return '(' . sqlOrdenPorAtender($columnaEstatus) . " AND DATEDIFF(CURDATE(), $columnaFecha) > " . (int)slaDiasEmpresa() . ')';
}
