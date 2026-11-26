<?php
// Equipos de la orden de servicio (D-41). Una orden atiende varios equipos (series); cada
// uno se liga al padrón del cliente. Si la serie no está en el padrón, se da de alta desde
// la orden con equipo, marca y modelo (lo puede hacer quien edita la orden, incluido el
// Técnico; en el padrón el Técnico sigue sin editar ni borrar).
require_once __DIR__ . '/incidencias_helpers.php';   // ordenVisiblePorId (D-9)
require_once __DIR__ . '/sucursales_helpers.php';    // sucLigarRegistro (D-44)

class EquipoOrdenException extends RuntimeException {
    public string $motivo;
    public int $http;
    public function __construct(string $mensaje, int $http = 400, string $motivo = '') {
        parent::__construct($mensaje);
        $this->http = $http;
        $this->motivo = $motivo;
    }
}

function eqoDisponible(PDO $pdo): bool {
    static $cache = [];
    $clave = EmpresaContexto::$id ?? 0;
    if (!isset($cache[$clave])) {
        try { $cache[$clave] = (bool)$pdo->query("SHOW TABLES LIKE 'incidencia_equipos'")->fetch(); } catch (Throwable $e) { $cache[$clave] = false; }
    }
    return $cache[$clave];
}

function eqoListar(PDO $pdo, int $incidenciaId): array {
    if (!eqoDisponible($pdo)) return [];
    $st = $pdo->prepare('SELECT e.id, e.numero_serie, e.padron_id, e.creado_por_nombre, e.creado_en,
                                p.equipo, p.marca, p.modelo, p.sucursal
                         FROM incidencia_equipos e LEFT JOIN padron_equipos p ON p.id = e.padron_id
                         WHERE e.incidencia_id = ? ORDER BY e.id');
    $st->execute([$incidenciaId]);
    return array_map(fn($f) => ['id' => (int)$f['id'], 'padron_id' => $f['padron_id'] ? (int)$f['padron_id'] : null] + $f, $st->fetchAll(PDO::FETCH_ASSOC));
}

/** Equipo del padrón con esa serie (sin distinguir mayúsculas): la fila o null. */
function eqoBuscarSerie(PDO $pdo, string $serie): ?array {
    $st = $pdo->prepare('SELECT id, cliente, sucursal, equipo, marca, modelo FROM padron_equipos WHERE UPPER(TRIM(numero_serie)) = UPPER(TRIM(?)) ORDER BY id LIMIT 1');
    $st->execute([$serie]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Agrega un equipo a la orden. $nuevo: equipo, marca, modelo para dar de alta una serie
 * que no está en el padrón (sin ellos responde 409 'equipo_nuevo' para que la interfaz
 * abra el modal). $silencioso: al guardar la orden, una serie ajena o nueva sin datos no
 * detiene el guardado (solo se liga lo que se puede).
 */
function eqoAgregar(PDO $pdo, int $incidenciaId, string $serie, array $nuevo = [], bool $silencioso = false): ?array {
    if (!eqoDisponible($pdo)) {
        if ($silencioso) return null;
        throw new EquipoOrdenException('Falta aplicar la migración 017 (equipos de la orden).', 503);
    }
    $serie = mb_substr(trim($serie), 0, 100);
    if ($serie === '') throw new EquipoOrdenException('Escribe el número de serie.');
    $propia = !$pdo->inTransaction();
    if ($propia) $pdo->beginTransaction();
    try {
        $orden = ordenVisiblePorId($pdo, $incidenciaId, true);  // D-9
        if (!$orden) throw new EquipoOrdenException('La orden de servicio no existe.', 404);
        $st = $pdo->prepare('SELECT id, padron_id FROM incidencia_equipos WHERE incidencia_id = ? AND numero_serie = ?');
        $st->execute([$incidenciaId, $serie]);
        $existente = $st->fetch(PDO::FETCH_ASSOC);
        // Una serie ya en la orden pero sin padrón (capturada al guardar) se puede dar de alta después
        if ($existente && ($existente['padron_id'] || $silencioso)) {
            if ($silencioso) { if ($propia) $pdo->commit(); return null; }
            throw new EquipoOrdenException("La serie $serie ya está en esta orden.");
        }

        $padron = eqoBuscarSerie($pdo, $serie);
        $cliente = trim((string)$orden['cliente']);
        if ($padron && mb_strtoupper(trim((string)$padron['cliente'])) !== mb_strtoupper($cliente)) {
            if ($silencioso) { if ($propia) $pdo->commit(); return null; }
            throw new EquipoOrdenException("La serie $serie está en el padrón de otro cliente. Revisa la serie.");
        }
        $alta = false;
        if (!$padron) {
            $datos = array_map(fn($v) => mb_substr(trim((string)$v), 0, 100), [
                'equipo' => $nuevo['equipo'] ?? '', 'marca' => $nuevo['marca'] ?? '', 'modelo' => $nuevo['modelo'] ?? '']);
            if (in_array('', $datos, true)) {
                if ($silencioso) {
                    // Al guardar la orden: la serie queda en la orden aunque aún no esté en el padrón
                    $pdo->prepare('INSERT INTO incidencia_equipos (incidencia_id, numero_serie, creado_por, creado_por_nombre) VALUES (?, ?, ?, ?)')
                        ->execute([$incidenciaId, $serie, $_SESSION['user_id'] ?? null, $_SESSION['nombre'] ?? null]);
                    if ($propia) $pdo->commit();
                    return null;
                }
                throw new EquipoOrdenException("La serie $serie no está en el padrón del cliente. Captura el equipo, la marca y el modelo para darla de alta.", 409, 'equipo_nuevo');
            }
            $pdo->prepare("INSERT INTO padron_equipos (cliente, sucursal, equipo, marca, modelo, numero_serie, origen, fecha_registro) VALUES (?, ?, ?, ?, ?, ?, 'Externo', CURDATE())")
                ->execute([$cliente, (string)$orden['sucursal'], $datos['equipo'], $datos['marca'], $datos['modelo'], $serie]);
            $padronId = (int)$pdo->lastInsertId();
            sucLigarRegistro($pdo, 'padron_equipos', $padronId);
            registrarAuditoria('padron_equipos', $padronId, 'CREATE', null, $datos + ['cliente' => $cliente, 'sucursal' => $orden['sucursal'],
                'numero_serie' => $serie, 'origen' => 'Alta desde la orden ' . $orden['numero_incidente']]);
            $padron = ['id' => $padronId] + $datos;
            $alta = true;
        }
        if ($existente) {
            $pdo->prepare('UPDATE incidencia_equipos SET padron_id = ? WHERE id = ?')->execute([(int)$padron['id'], (int)$existente['id']]);
        } else {
            $pdo->prepare('INSERT INTO incidencia_equipos (incidencia_id, padron_id, numero_serie, creado_por, creado_por_nombre) VALUES (?, ?, ?, ?, ?)')
                ->execute([$incidenciaId, (int)$padron['id'], $serie, $_SESSION['user_id'] ?? null, $_SESSION['nombre'] ?? null]);
        }
        registrarAuditoria('incidencias', $incidenciaId, 'CONCEPTO', null, ['equipo_agregado' => $serie, 'alta_en_padron' => $alta], $orden['numero_incidente']);
        if ($propia) $pdo->commit();
        return ['padron_id' => (int)$padron['id'], 'alta_en_padron' => $alta];
    } catch (Throwable $e) {
        if ($propia && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** Quita el equipo de la orden (no lo borra del padrón). */
function eqoQuitar(PDO $pdo, int $incidenciaId, int $id): void {
    $orden = ordenVisiblePorId($pdo, $incidenciaId);  // D-9
    if (!$orden) throw new EquipoOrdenException('La orden de servicio no existe.', 404);
    $st = $pdo->prepare('SELECT numero_serie FROM incidencia_equipos WHERE id = ? AND incidencia_id = ?');
    $st->execute([$id, $incidenciaId]);
    $serie = $st->fetchColumn();
    if ($serie === false) throw new EquipoOrdenException('Ese equipo ya no está en la orden.', 404);
    $pdo->prepare('DELETE FROM incidencia_equipos WHERE id = ?')->execute([$id]);
    registrarAuditoria('incidencias', $incidenciaId, 'CONCEPTO', ['equipo' => $serie], ['equipo_quitado' => $serie], $orden['numero_incidente']);
}
