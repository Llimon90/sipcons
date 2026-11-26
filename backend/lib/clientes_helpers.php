<?php
// Catálogo de clientes: unicidad (D-53), renombre (L-4) y baja (L-5).
// El nombre del cliente es el texto que liga sus órdenes, su padrón y sus ventas
// (incidencias.cliente, padron_equipos.cliente, ventas.cliente). Requiere $pdo.

const CLI_MAX_NOMBRE = 100;  // clientes.nombre es varchar(100) (L-33)

function cliNormalizarNombre(string $nombre): string {
    return trim(preg_replace('/\s+/u', ' ', $nombre));
}

/**
 * D-53: el nombre y el correo de un cliente no se repiten dentro de la empresa
 * (sin distinguir mayúsculas ni espacios de sobra). El correo puede quedar vacío.
 * Devuelve el mensaje de error, o null si los datos sirven.
 */
function cliErrorDatos(PDO $pdo, string $nombre, string $email, int $excluirId = 0, ?array $anterior = null): ?string {
    // L2-4: al editar, la unicidad se exige solo a lo que cambia (un duplicado heredado no bloquea editar lo demás)
    $mismoNombre = $anterior !== null && mb_strtolower(cliNormalizarNombre((string)$anterior['nombre'])) === mb_strtolower($nombre);
    $mismoCorreo = $anterior !== null && mb_strtolower(trim((string)$anterior['email'])) === mb_strtolower(trim($email));
    if ($nombre === '') return 'Escribe el nombre del cliente.';
    if (mb_strlen($nombre) > CLI_MAX_NOMBRE) return 'El nombre del cliente admite hasta ' . CLI_MAX_NOMBRE . ' caracteres.';
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) return 'El correo del cliente no es válido.';
    $st = $pdo->prepare('SELECT id FROM clientes WHERE LOWER(TRIM(nombre)) = LOWER(?) AND id <> ? LIMIT 1');
    $st->execute([$nombre, $excluirId]);
    if (!$mismoNombre && $st->fetchColumn()) return 'Ya existe un cliente con ese nombre.';
    if ($email !== '' && !$mismoCorreo) {
        $st = $pdo->prepare("SELECT nombre FROM clientes WHERE LOWER(TRIM(email)) = LOWER(?) AND email <> '' AND id <> ? LIMIT 1");
        $st->execute([$email, $excluirId]);
        if ($otro = $st->fetchColumn()) return "El correo ya es del cliente $otro.";
    }
    return null;
}

/** Serializa altas y cambios de clientes de la empresa (dos altas simultáneas con el mismo nombre). */
function cliCandado(PDO $pdo): string {
    // S2-1: los candados de MySQL son de todo el servidor: el nombre lleva la base de datos de esta instancia
    $nombre = 'sd-cli-' . md5((string)env('DB_NAME', '') . '|' . (EmpresaContexto::$id ?? 0));
    $st = $pdo->prepare('SELECT GET_LOCK(?, 10)');
    $st->execute([$nombre]);
    if ((int)$st->fetchColumn() !== 1) throw new RuntimeException('El catálogo de clientes está ocupado. Inténtalo de nuevo.');
    return $nombre;
}
function cliSoltarCandado(PDO $pdo, string $nombre): void {
    $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$nombre]);
}

/**
 * L-4: al renombrar un cliente, sus órdenes, su padrón y sus ventas lo siguen (se ligan por
 * nombre). Los documentos emitidos (cotizaciones, cuentas por cobrar) conservan el nombre con
 * que se emitieron y se ligan por id. Llamar dentro de una transacción.
 */
/** L2-3: ¿otro cliente del catálogo tiene el mismo nombre (duplicado heredado)? Renombrar arrastraría su historial. */
function cliDuplicadoDeNombre(PDO $pdo, string $nombre, int $id): ?string {
    $st = $pdo->prepare('SELECT nombre FROM clientes WHERE UPPER(TRIM(nombre)) = UPPER(TRIM(?)) AND id <> ? LIMIT 1');
    $st->execute([$nombre, $id]);
    $otro = $st->fetchColumn();
    return $otro === false ? null : (string)$otro;
}

function cliRenombrar(PDO $pdo, string $viejo, string $nuevo): array {
    $cambios = [];
    if ($viejo === $nuevo) return $cambios;
    foreach (['incidencias' => 'órdenes', 'padron_equipos' => 'equipos', 'ventas' => 'ventas'] as $tabla => $etiqueta) {
        try {
            // H-10: también los registros guardados con espacios de sobra o con otras mayúsculas
            $st = $pdo->prepare("UPDATE $tabla SET cliente = ? WHERE UPPER(TRIM(cliente)) = UPPER(TRIM(?))");
            $st->execute([$nuevo, $viejo]);
            if ($st->rowCount()) $cambios[$etiqueta] = $st->rowCount();
        } catch (PDOException $e) {
            if (!in_array($e->getCode(), ['42S02', '42S22'], true)) throw $e;  // tabla ausente en esta empresa
        }
    }
    return $cambios;
}

/** L-5: lo que impide borrar a un cliente (su historial y su dinero). Null si se puede. */
function cliErrorEliminar(PDO $pdo, int $id, string $nombre): ?string {
    $historial = [];
    foreach ([
        'órdenes de servicio' => ['SELECT COUNT(*) FROM incidencias WHERE UPPER(TRIM(cliente)) = UPPER(TRIM(?))', $nombre],
        'ventas'              => ['SELECT COUNT(*) FROM ventas WHERE UPPER(TRIM(cliente)) = UPPER(TRIM(?))', $nombre],
        'equipos'             => ['SELECT COUNT(*) FROM padron_equipos WHERE UPPER(TRIM(cliente)) = UPPER(TRIM(?))', $nombre],
        'cotizaciones'        => ['SELECT COUNT(*) FROM cotizaciones WHERE cliente_id = ?', $id],
        'cuentas por cobrar'  => ['SELECT COUNT(*) FROM cuentas_cobrar WHERE cliente_id = ?', $id],
    ] as $tipo => [$sql, $valor]) {
        try {
            $st = $pdo->prepare($sql);
            $st->execute([$valor]);
            if ($n = (int)$st->fetchColumn()) $historial[] = "$n $tipo";
        } catch (PDOException $e) {
            if (!in_array($e->getCode(), ['42S02', '42S22'], true)) throw $e;
        }
    }
    return $historial ? 'No se puede eliminar: el cliente tiene ' . implode(', ', $historial) . '. Se conserva para no perder ese historial.' : null;
}
