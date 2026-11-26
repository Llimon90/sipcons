<?php
// Multisucursal (D-44). Cada cliente tiene su catálogo de sucursales (cliente_sucursales).
// Órdenes, padrón, ventas y cotizaciones guardan sucursal_id además del texto `sucursal`
// (que se conserva como historial). Quien captura escribe o elige la sucursal; aquí se liga
// al catálogo y, si es nueva, se da de alta (así los formularios existentes siguen igual).
// sucSincronizarCliente() liga lo que haya quedado solo con texto (datos anteriores).
require_once __DIR__ . '/incidencias_helpers.php';  // ESTATUS_FINALES, sqlEstatusActivo

const SUC_TABLAS_CON_NOMBRE = ['incidencias', 'padron_equipos', 'ventas'];  // ligan al cliente por su nombre

class SucursalException extends RuntimeException {}

/** ¿Ya se aplicó la migración 017? */
function sucTablaDisponible(PDO $pdo): bool {
    static $cache = [];
    $clave = EmpresaContexto::$id ?? 0;
    if (!isset($cache[$clave])) {
        try { $cache[$clave] = (bool)$pdo->query("SHOW TABLES LIKE 'cliente_sucursales'")->fetch(); } catch (Throwable $e) { $cache[$clave] = false; }
    }
    return $cache[$clave];
}

function sucNormalizar(?string $texto): string {
    return mb_substr(trim(preg_replace('/\s+/u', ' ', (string)$texto)), 0, 150);
}

/** Id del cliente del catálogo por su nombre (sin distinguir mayúsculas ni espacios), o null. */
function sucClienteIdPorNombre(PDO $pdo, string $cliente): ?int {
    $st = $pdo->prepare('SELECT id FROM clientes WHERE UPPER(TRIM(nombre)) = UPPER(TRIM(?)) ORDER BY id LIMIT 1');
    $st->execute([$cliente]);
    $id = (int)$st->fetchColumn();
    return $id ?: null;
}

/**
 * Sucursal del cliente con ese nombre; la crea si no existe. Devuelve [id, nombre del
 * catálogo] o [null, texto] si no hay sucursal o el cliente no está en el catálogo.
 */
function sucResolver(PDO $pdo, ?int $clienteId, ?string $texto): array {
    $nombre = sucNormalizar($texto);
    if ($nombre === '' || !$clienteId || !sucTablaDisponible($pdo)) return [null, $nombre];
    $st = $pdo->prepare('SELECT id, nombre, activo FROM cliente_sucursales WHERE cliente_id = ? AND nombre = ? LIMIT 1');
    $st->execute([$clienteId, $nombre]);
    if ($f = $st->fetch(PDO::FETCH_ASSOC)) {
        // L-32: usar una sucursal desactivada la vuelve a activar (se le está dando servicio)
        if (!(int)$f['activo']) $pdo->prepare('UPDATE cliente_sucursales SET activo = 1 WHERE id = ?')->execute([(int)$f['id']]);
        return [(int)$f['id'], $f['nombre']];
    }
    $pdo->prepare('INSERT IGNORE INTO cliente_sucursales (cliente_id, nombre) VALUES (?, ?)')->execute([$clienteId, $nombre]);
    $st->execute([$clienteId, $nombre]);
    $f = $st->fetch(PDO::FETCH_ASSOC);
    return $f ? [(int)$f['id'], $f['nombre']] : [null, $nombre];
}

/** Igual que sucResolver(), con el cliente por su nombre (órdenes, padrón y ventas). */
function sucResolverPorCliente(PDO $pdo, string $cliente, ?string $texto): array {
    return sucResolver($pdo, sucClienteIdPorNombre($pdo, $cliente), $texto);
}

/** Liga un registro ya guardado a su sucursal (después de un INSERT o UPDATE con texto). */
function sucLigarRegistro(PDO $pdo, string $tabla, int $id): void {
    if (!sucTablaDisponible($pdo) || !in_array($tabla, [...SUC_TABLAS_CON_NOMBRE, 'cotizaciones'], true)) return;
    $st = $pdo->prepare("SELECT " . ($tabla === 'cotizaciones' ? 'cliente_id' : 'cliente') . " AS cliente, sucursal FROM `$tabla` WHERE id = ?");
    $st->execute([$id]);
    $f = $st->fetch(PDO::FETCH_ASSOC);
    if (!$f) return;
    [$sucId, $nombre] = $tabla === 'cotizaciones'
        ? sucResolver($pdo, $f['cliente'] ? (int)$f['cliente'] : null, $f['sucursal'])
        : sucResolverPorCliente($pdo, (string)$f['cliente'], $f['sucursal']);
    $pdo->prepare("UPDATE `$tabla` SET sucursal_id = ?, sucursal = ? WHERE id = ?")->execute([$sucId, $sucId ? $nombre : (string)$f['sucursal'], $id]);
}

/** Liga a las sucursales del cliente los registros que solo tienen el texto (datos anteriores a 017). */
function sucSincronizarCliente(PDO $pdo, int $clienteId): void {
    if (function_exists('empresaAdmiteEscrituras') && !empresaAdmiteEscrituras()) return;  // L-20: solo lectura
    if (!sucTablaDisponible($pdo)) return;
    $st = $pdo->prepare('SELECT nombre FROM clientes WHERE id = ?');
    $st->execute([$clienteId]);
    $cliente = $st->fetchColumn();
    if ($cliente === false) return;
    foreach (SUC_TABLAS_CON_NOMBRE as $tabla) {
        $st = $pdo->prepare("SELECT id FROM `$tabla` WHERE sucursal_id IS NULL AND TRIM(COALESCE(sucursal, '')) <> '' AND UPPER(TRIM(cliente)) = UPPER(TRIM(?))");
        $st->execute([$cliente]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) sucLigarRegistro($pdo, $tabla, (int)$id);
    }
    $st = $pdo->prepare("SELECT id FROM cotizaciones WHERE sucursal_id IS NULL AND TRIM(COALESCE(sucursal, '')) <> '' AND cliente_id = ?");
    $st->execute([$clienteId]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) sucLigarRegistro($pdo, 'cotizaciones', (int)$id);
}

/** sucSincronizarCliente() con el cliente por su nombre (si está en el catálogo). */
function sucSincronizarPorNombre(PDO $pdo, string $cliente): void {
    if ($cliente === '' || !sucTablaDisponible($pdo)) return;
    $id = sucClienteIdPorNombre($pdo, $cliente);
    if ($id) sucSincronizarCliente($pdo, $id);
}

/** Sucursales del cliente con sus equipos en el padrón y sus órdenes (activas y totales). */
function sucListar(PDO $pdo, int $clienteId, bool $soloActivas = true): array {
    if (!sucTablaDisponible($pdo)) return [];
    sucSincronizarCliente($pdo, $clienteId);
    $st = $pdo->prepare('SELECT s.id, s.nombre, s.direccion, s.contacto, s.telefono, s.activo,
            (SELECT COUNT(*) FROM padron_equipos p WHERE p.sucursal_id = s.id) AS equipos,
            (SELECT COUNT(*) FROM incidencias i WHERE i.sucursal_id = s.id AND ' . sqlEstatusActivo('i.estatus') . ') AS ordenes_activas,
            (SELECT COUNT(*) FROM incidencias i WHERE i.sucursal_id = s.id) AS ordenes
        FROM cliente_sucursales s WHERE s.cliente_id = ?' . ($soloActivas ? ' AND s.activo = 1' : '') . ' ORDER BY s.nombre');
    $st->execute([$clienteId]);
    return array_map(function ($f) {
        foreach (['id', 'activo', 'equipos', 'ordenes_activas', 'ordenes'] as $c) $f[$c] = (int)$f[$c];
        return $f;
    }, $st->fetchAll(PDO::FETCH_ASSOC));
}

/**
 * Crea o edita una sucursal. Al renombrar, el texto se actualiza en el padrón y en las
 * órdenes activas (las cerradas, las ventas y las cotizaciones conservan el nombre de entonces).
 * Devuelve [antes|null, después].
 */
function sucGuardar(PDO $pdo, array $d): array {
    if (!sucTablaDisponible($pdo)) throw new SucursalException('Falta aplicar la migración 017 (sucursales).');
    $clienteId = (int)($d['cliente_id'] ?? 0);
    $id = (int)($d['id'] ?? 0);
    $nombre = sucNormalizar($d['nombre'] ?? '');
    if ($nombre === '') throw new SucursalException('Escribe el nombre de la sucursal.');
    $st = $pdo->prepare('SELECT id FROM clientes WHERE id = ?');
    $st->execute([$clienteId]);
    if (!$st->fetchColumn()) throw new SucursalException('El cliente no existe.');
    $campos = [
        'nombre' => $nombre,
        'direccion' => mb_substr(trim((string)($d['direccion'] ?? '')), 0, 255) ?: null,
        'contacto' => mb_substr(trim((string)($d['contacto'] ?? '')), 0, 150) ?: null,
        'telefono' => mb_substr(trim((string)($d['telefono'] ?? '')), 0, 30) ?: null,
        'activo' => isset($d['activo']) ? ((int)$d['activo'] ? 1 : 0) : 1,
    ];
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT id FROM cliente_sucursales WHERE cliente_id = ? AND nombre = ? AND id <> ?');
        $st->execute([$clienteId, $nombre, $id]);
        if ($st->fetchColumn()) throw new SucursalException("El cliente ya tiene una sucursal llamada \"$nombre\".");
        $antes = null;
        if ($id) {
            $st = $pdo->prepare('SELECT * FROM cliente_sucursales WHERE id = ? AND cliente_id = ? FOR UPDATE');
            $st->execute([$id, $clienteId]);
            $antes = $st->fetch(PDO::FETCH_ASSOC);
            if (!$antes) throw new SucursalException('La sucursal no existe.');
            $pdo->prepare('UPDATE cliente_sucursales SET nombre = ?, direccion = ?, contacto = ?, telefono = ?, activo = ? WHERE id = ?')
                ->execute([...array_values($campos), $id]);
            if ($antes['nombre'] !== $nombre) {
                $pdo->prepare('UPDATE padron_equipos SET sucursal = ? WHERE sucursal_id = ?')->execute([$nombre, $id]);
                $pdo->prepare('UPDATE incidencias SET sucursal = ? WHERE sucursal_id = ? AND ' . sqlEstatusActivo('estatus'))->execute([$nombre, $id]);
            }
        } else {
            $pdo->prepare('INSERT INTO cliente_sucursales (cliente_id, nombre, direccion, contacto, telefono, activo) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$clienteId, ...array_values($campos)]);
            $id = (int)$pdo->lastInsertId();
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    $st = $pdo->prepare('SELECT * FROM cliente_sucursales WHERE id = ?');
    $st->execute([$id]);
    return [$antes, $st->fetch(PDO::FETCH_ASSOC)];
}
