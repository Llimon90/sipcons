<?php
// Técnicos asignados a órdenes de servicio (D-10, D-11, D-32, AS-7).
//
// incidencias.tecnico guarda los nombres separados por "/" (alias_tecnico o nombre
// del usuario). Aquí se valida al asignar y se mantiene al día cuando un usuario se
// elimina, cambia de rol o cambia de nombre. Requiere $pdo y la empresa activa.

if (!defined('ROLES_TECNICO_ASIGNABLE')) define('ROLES_TECNICO_ASIGNABLE', ['Técnico', 'Supervisor', 'Administrador']);
require_once __DIR__ . '/incidencias_helpers.php';  // ESTATUS_FINALES (D-4: una sola definición)

/** Expresión SQL del nombre con que el usuario aparece en las órdenes (alias o nombre). */
function sqlIdentidadUsuario(PDO $pdo): string {
    static $expr = null;
    if ($expr === null) {
        try {
            $hayAlias = (bool)$pdo->query("SHOW COLUMNS FROM usuarios LIKE 'alias_tecnico'")->fetch();
        } catch (Throwable $e) {
            $hayAlias = false;
        }
        $expr = $hayAlias ? "COALESCE(NULLIF(TRIM(alias_tecnico), ''), TRIM(nombre))" : 'TRIM(nombre)';
    }
    return $expr;
}

/** Técnicos asignables de la empresa en sesión: [nombre en minúsculas => nombre tal cual]. */
function tecnicosAsignables(PDO $pdo): array {
    $marcas = implode(',', array_fill(0, count(ROLES_TECNICO_ASIGNABLE), '?'));
    $st = $pdo->prepare('SELECT ' . sqlIdentidadUsuario($pdo) . " AS valor FROM usuarios
                         WHERE empresa_id = ? AND rol IN ($marcas) AND TRIM(COALESCE(nombre, '')) <> ''");
    $st->execute([(int)($_SESSION['empresa_id'] ?? 0), ...ROLES_TECNICO_ASIGNABLE]);
    $mapa = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $v) {
        $v = trim(preg_replace('/ +/u', ' ', $v));
        $mapa[mb_strtolower($v)] = $v;
    }
    return $mapa;
}

/**
 * Valida y normaliza el campo "tecnico" recibido. Cada nombre debe ser un técnico
 * asignable de la empresa; al editar se permite conservar los que ya estaban en la
 * orden ($anteriores, p. ej. un exempleado en una orden vieja). Quita repetidos y
 * espacios. Devuelve [texto normalizado, null] o [null, mensaje de error].
 */
function normalizarTecnicosOrden(PDO $pdo, string $entrada, string $anteriores = ''): array {
    $validos = tecnicosAsignables($pdo);
    $historicos = [];
    foreach (explode('/', $anteriores) as $t) {
        $t = trim(preg_replace('/ +/u', ' ', $t));
        if ($t !== '') $historicos[mb_strtolower($t)] = $t;
    }
    $salida = [];
    $invalidos = [];
    foreach (explode('/', $entrada) as $t) {
        $t = trim(preg_replace('/\s+/u', ' ', $t));
        if ($t === '') continue;
        $clave = mb_strtolower($t);
        if (isset($salida[$clave])) continue;
        if (isset($validos[$clave])) $salida[$clave] = $validos[$clave];
        elseif (isset($historicos[$clave])) $salida[$clave] = $historicos[$clave];
        else $invalidos[] = $t;
    }
    if ($invalidos) {
        return [null, (count($invalidos) === 1 ? 'Este técnico no es un usuario asignable de la empresa: ' : 'Estos técnicos no son usuarios asignables de la empresa: ')
            . implode(', ', $invalidos) . '. Elige técnicos de la lista.'];
    }
    return [implode('/', $salida), null];
}

/**
 * Cambia (o quita, si $nuevo es null) a un técnico en las órdenes ACTIVAS de la
 * empresa; las cerradas y canceladas conservan el nombre (historial), y en una baja
 * ($nuevo null) también las Completado (D-40). Una orden en
 * Asignado o En proceso que se queda sin técnicos regresa a Abierto (D-5, D-11).
 * Devuelve cuántas órdenes cambiaron. Llamar dentro de una transacción.
 */
function reemplazarTecnicoEnOrdenesActivas(PDO $pdo, string $identidad, ?string $nuevo, string $motivo): int {
    $identidad = trim($identidad);
    if ($identidad === '') return 0;
    require_once __DIR__ . '/estadisticas_helpers.php';
    require_once __DIR__ . '/../../auth/audit.php';
    // D-40: en una baja, las órdenes Completado conservan al técnico (el trabajo sí lo hizo él)
    $conservar = $nuevo === null ? [...ESTATUS_FINALES, 'Completado'] : ESTATUS_FINALES;
    $marcas = implode(',', array_fill(0, count($conservar), '?'));
    $st = $pdo->prepare("SELECT id, numero_incidente, tecnico, estatus FROM incidencias
                         WHERE tecnico LIKE ? AND (estatus IS NULL OR estatus NOT IN ($marcas)) FOR UPDATE");
    $st->execute(['%' . addcslashes($identidad, '%_' . chr(92)) . '%', ...$conservar]);
    $upd = $pdo->prepare('UPDATE incidencias SET tecnico = ?, estatus = ? WHERE id = ?');
    $n = 0;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $f) {
        if (!tecnicoCoincide($identidad, $f['tecnico'] ?? '')) continue;
        $lista = [];
        foreach (separarTecnicos($f['tecnico']) as $t) {
            if (mb_strtolower($t) === mb_strtolower($identidad)) {
                if ($nuevo !== null && trim($nuevo) !== '') $lista[mb_strtolower($nuevo)] = trim($nuevo);
            } else {
                $lista[mb_strtolower($t)] = $t;
            }
        }
        $tecnicos = implode('/', $lista);
        $estatus = $f['estatus'];
        if ($tecnicos === '' && in_array($estatus, ['Asignado', 'En proceso'], true)) $estatus = 'Abierto';
        $upd->execute([$tecnicos, $estatus, $f['id']]);
        registrarAuditoria('incidencias', $f['id'], 'UPDATE', ['tecnico' => $f['tecnico'], 'estatus' => $f['estatus']],
            ['tecnico' => $tecnicos, 'estatus' => $estatus, 'motivo' => $motivo], $f['numero_incidente']);
        $n++;
    }
    return $n;
}

/**
 * Error si el nombre no sirve como identidad de técnico en la empresa: no puede
 * llevar "/" (separador) ni repetirse con otro usuario de la misma empresa (sin
 * distinguir mayúsculas), comparando contra su nombre y su alias. Null si sirve.
 */
function errorNombreUsuario(PDO $pdo, string $nombre, int $empresaId, int $excluirId = 0): ?string {
    if (strpos($nombre, '/') !== false) return 'El nombre no puede llevar "/" (se usa para separar técnicos en las órdenes).';
    $st = $pdo->prepare('SELECT COUNT(*) FROM usuarios WHERE empresa_id = ? AND id <> ?
                         AND (LOWER(TRIM(nombre)) = ? OR LOWER(' . sqlIdentidadUsuario($pdo) . ') = ?)');
    $clave = mb_strtolower(trim($nombre));
    $st->execute([$empresaId, $excluirId, $clave, $clave]);
    if ((int)$st->fetchColumn() > 0) return 'Ya hay otro usuario con ese nombre en la empresa. Agrega un apellido o una inicial para distinguirlos.';
    return null;
}
