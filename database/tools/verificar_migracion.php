<?php
/**
 * Verificación de COMPLETITUD de la migración SIPCONS -> Servidesk.
 *
 * Responde a una sola pregunta, con números y no con confianza en el código:
 *   ¿quedó alguna tabla del ORIGEN con datos que NO llegó al DESTINO?
 *
 * Es "a prueba de olvidos" (fail-closed): recorre TODAS las tablas base del
 * origen y exige que cada una esté en uno de estos casos:
 *   - MAPEADA      -> se compara el conteo origen vs destino (debe ser igual).
 *   - EXCLUIDA     -> está en la lista blanca de exclusiones, con su motivo.
 *   - VACÍA        -> 0 filas: no hay nada que perder.
 * Cualquier tabla con filas que NO esté mapeada ni excluida => FALLA (rojo).
 * Una tabla mapeada cuyos conteos no cuadran => FALLA (rojo).
 *
 * El código de salida es 0 solo si NO hay fallas. Pensado para correr DESPUÉS
 * de migrar sobre una COPIA, antes de cualquier cambio en producción.
 *
 *   C:\xampp\php\php.exe database\tools\verificar_migracion.php \
 *       --origen=sipcons_prod_copia --destino=servidesk_prod_copia [--empresa=1]
 *
 * El repo es PÚBLICO: este archivo no contiene datos; todo sale de las bases en
 * tiempo de ejecución.
 */

declare(strict_types=1);
mb_internal_encoding('UTF-8');

$opts = getopt('', ['origen:', 'destino:', 'host:', 'user:', 'pass:', 'empresa:']);
$ORIGEN  = $opts['origen']  ?? null;
$DESTINO = $opts['destino'] ?? null;
$HOST    = $opts['host']    ?? '127.0.0.1';
$USER    = $opts['user']    ?? 'root';
$PASS    = $opts['pass']    ?? '';
$EMPRESA = (int)($opts['empresa'] ?? 1);
$P = 'e' . $EMPRESA . '_';

if (!$ORIGEN || !$DESTINO) {
    fwrite(STDERR, "Faltan --origen y/o --destino.\n");
    exit(2);
}

/* ─────────────────────────────────────────────────────────────────────────
 * MAPEO: tabla_origen => tabla_destino. Debe reflejar EXACTAMENTE lo que hace
 * migrar_sipcons_a_servidesk.php. Si agregas una migración allá, agrégala aquí.
 * ───────────────────────────────────────────────────────────────────────── */
$MAPEO = [
    'marcas'               => "{$P}marcas",
    'modelos'              => "{$P}modelos",
    'documentos'           => "{$P}documentos",
    'clientes'             => "{$P}clientes",
    'cliente_contactos'    => "{$P}cliente_contactos",
    'incidencias'          => "{$P}incidencias",
    'archivos_incidencias' => "{$P}archivos_incidencias",
    'ventas'               => "{$P}ventas",
    'venta_detalles'       => "{$P}venta_detalles",
    'venta_archivos'       => "{$P}venta_archivos",
    'archivos_ventas'      => "{$P}archivos_ventas",
    'padron_equipos'       => "{$P}padron_equipos",
    'auditoria'            => "{$P}auditoria",       // requiere --migrar-auditoria en la migración
    'usuarios'             => 'usuarios',            // central (empresa_id); se filtra abajo
];

/* Tablas del destino que son CENTRALES (no llevan prefijo) y donde hay que
 * filtrar por empresa_id al contar, porque pueden contener otras empresas. */
$DESTINO_FILTRA_EMPRESA = ['usuarios' => true];

/* ─────────────────────────────────────────────────────────────────────────
 * EXCLUSIONES: tablas del origen que a propósito NO se migran, con su motivo.
 * Si una de estas trae filas, se reporta como AVISO (no rompe), para que el
 * dueño confirme que de verdad no las quiere. Revísala contra la estructura
 * REAL de producción antes del corte.
 * ───────────────────────────────────────────────────────────────────────── */
$EXCLUSIONES = [
    // Plataforma / Solid Suite / superadmin: no aplican a SIPCONS como inquilino único.
    'planes'            => 'Catálogo de planes de la plataforma (Solid Suite), no datos de la empresa.',
    'pagos'             => 'Cobros de la plataforma a la empresa, no del negocio.',
    'pagos_cxc'         => 'Pagos de cuentas por cobrar (módulo que no existe en Servidesk real).',
    'solicitudes_pago'  => 'Flujo de contratación de la plataforma.',
    'login_intentos'    => 'Telemetría de seguridad efímera; no es dato de negocio.',
    'plataforma_config' => 'Configuración del panel de plataforma (superadmin).',
    'plataforma_eventos'=> 'Bitácora del panel de plataforma (superadmin).',
    'empresa_notas'     => 'Notas internas de la plataforma sobre la empresa.',
    'empresas'          => 'La empresa 1 la crea/ajusta la propia migración.',
    'analytics_eventos' => 'Telemetría de uso: arranca de cero por decisión del dueño (2026-11-26); no se migra.',
    'permisos_rol'      => 'Decisión del dueño (2026-11-26): se quedan los 44 permisos por rol default de Servidesk; no se portan los del origen.',

    // Funciones ITIL de la noche 2026-11-26 que NO forman parte de Servidesk real.
    // Aparecen en el dump "errático" de pruebas; en producción no existen.
    'bitacora_tiempo'      => 'ITIL no portado a Servidesk.',
    'cambios'              => 'ITIL (gestión de cambios) no portado.',
    'catalogo_servicios'   => 'ITIL (catálogo) no portado.',
    'cotizaciones'         => 'Cotizaciones viejas de SIPCONS; Servidesk usa e1_cotizaciones (flujo distinto).',
    'cotizacion_detalles'  => 'Idem cotizaciones.',
    'cuentas_por_cobrar'   => 'CxC vieja; Servidesk usa e1_cuentas_cobrar (flujo distinto).',
    'encuestas_csat'       => 'CSAT no portado.',
    'etiquetas'            => 'Etiquetas ITIL no portadas.',
    'incidencia_comentarios'=> 'Comentarios ITIL no portados.',
    'incidencia_etiquetas' => 'Etiquetas ITIL no portadas.',
    'inventario_movimientos'=> 'Inventario viejo; Servidesk usa e1_inventario_movimientos.',
    'inventario_productos' => 'Inventario viejo; Servidesk usa e1_inventario_articulos.',
    'kb_articulos'         => 'Base de conocimiento no portada.',
    'plantillas_respuesta' => 'Plantillas ITIL no portadas.',
    'problemas'            => 'Gestión de problemas ITIL no portada.',
    'problema_incidencias' => 'Idem problemas.',
    'reglas_automatizacion'=> 'Automatizaciones ITIL no portadas.',
    'sla_reglas'           => 'SLA configurable (trabajo nocturno); Servidesk real trae SLA fijo.',
    'solicitudes_servicio' => 'Solicitudes de servicio ITIL no portadas.',
];

/* Tablas que requieren revisión humana aunque no "fallen" por conteo.
 * (Las decisiones del 2026-11-26 ya quedaron resueltas en EXCLUSIONES.) */
$REVISAR = [];

try {
    $pdo = new PDO("mysql:host=$HOST;charset=utf8mb4", $USER, $PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    $pdo->exec('SET NAMES utf8mb4');
} catch (PDOException $e) {
    fwrite(STDERR, 'No se pudo conectar a MySQL: ' . $e->getMessage() . "\n");
    exit(2);
}

function tablasBase(PDO $pdo, string $base): array {
    $st = $pdo->prepare("SELECT table_name FROM information_schema.tables WHERE table_schema = ? AND table_type = 'BASE TABLE' ORDER BY table_name");
    $st->execute([$base]);
    return array_column($st->fetchAll(), 'table_name');
}
function existeTabla(PDO $pdo, string $base, string $tabla): bool {
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = ?');
    $st->execute([$base, $tabla]);
    return (bool)$st->fetchColumn();
}
function cuenta(PDO $pdo, string $base, string $tabla, string $where = ''): int {
    $sql = "SELECT COUNT(*) FROM `$base`.`$tabla`" . ($where ? " WHERE $where" : '');
    return (int)$pdo->query($sql)->fetchColumn();
}

foreach ([$ORIGEN, $DESTINO] as $b) {
    if (!existeTabla($pdo, 'information_schema', 'tables')) {} // no-op
    $hay = $pdo->prepare('SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = ?');
    $hay->execute([$b]);
    if (!$hay->fetchColumn()) { fwrite(STDERR, "La base '$b' no existe.\n"); exit(2); }
}

echo "== Verificación de completitud: $ORIGEN -> $DESTINO (empresa $EMPRESA) ==\n\n";

$fallas = [];
$avisos = [];
$ok = [];
$vacias = [];

$tablasOrigen = tablasBase($pdo, $ORIGEN);
// Se ignoran las tablas del origen que YA tienen prefijo de empresa (e1_, e7_…):
// son residuo del dump "errático" de pruebas, no fuente real.
$tablasOrigen = array_values(array_filter($tablasOrigen, fn($t) => !preg_match('/^e\d+_/', $t)));

foreach ($tablasOrigen as $t) {
    $filas = cuenta($pdo, $ORIGEN, $t);

    if (isset($MAPEO[$t])) {
        $dest = $MAPEO[$t];
        if (!existeTabla($pdo, $DESTINO, $dest)) {
            $fallas[] = sprintf("%-24s %6d filas -> destino `%s` NO EXISTE", $t, $filas, $dest);
            continue;
        }
        $where = isset($DESTINO_FILTRA_EMPRESA[$dest]) ? "empresa_id = $EMPRESA" : '';
        $filasDest = cuenta($pdo, $DESTINO, $dest, $where);
        if ($filas === $filasDest) {
            $ok[] = sprintf("%-24s %6d = %-24s %6d", $t, $filas, $dest, $filasDest);
        } else {
            $fallas[] = sprintf("%-24s %6d != %-22s %6d  (diferencia %+d)", $t, $filas, $dest, $filasDest, $filasDest - $filas);
        }
        continue;
    }

    if (isset($EXCLUSIONES[$t])) {
        if ($filas > 0) {
            $avisos[] = sprintf("%-24s %6d filas EXCLUIDA: %s", $t, $filas, $EXCLUSIONES[$t]);
        } else {
            $vacias[] = $t;
        }
        continue;
    }

    // Ni mapeada ni excluida:
    if ($filas > 0) {
        $fallas[] = sprintf("%-24s %6d filas  <-- SIN MAPEO NI EXCLUSIÓN (riesgo de pérdida)", $t, $filas);
    } else {
        $vacias[] = $t;
    }
}

echo "-- OK (origen = destino) --------------------------------------------\n";
foreach ($ok as $l) echo "  ✅ $l\n";
if (!$ok) echo "  (ninguna)\n";

if ($avisos) {
    echo "\n-- AVISO (excluidas con datos: confirmar que no se quieren) ----------\n";
    foreach ($avisos as $l) echo "  ⚠️  $l\n";
}

if ($REVISAR) {
    echo "\n-- REVISAR (decisión del dueño) -------------------------------------\n";
    foreach ($REVISAR as $t => $motivo) {
        if (in_array($t, $tablasOrigen, true)) echo "  ❓ $t: $motivo\n";
    }
}

if ($vacias) {
    echo "\n-- Vacías (0 filas, sin riesgo): " . count($vacias) . " tablas --------------------\n";
    echo "   " . implode(', ', $vacias) . "\n";
}

// Rutas de adjuntos: deben apuntar a la carpeta de la empresa (uploads/e<ID>/,
// manuales/e<ID>/). Si quedó alguna sin el segmento, backend/archivo.php le daría
// 404 y el adjunto se vería roto tras el corte. Esta comprobación es la red de
// seguridad del bug de rutas (2026-11-27).
$tablasRuta = ["{$P}archivos_incidencias", "{$P}venta_archivos", "{$P}documentos"];
foreach ($tablasRuta as $t) {
    if (!existeTabla($pdo, $DESTINO, $t)) continue;
    $cols = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=? AND table_name=? AND column_name='ruta_archivo'");
    $cols->execute([$DESTINO, $t]);
    if (!$cols->fetchColumn()) continue;
    $sql = "SELECT COUNT(*) FROM `$DESTINO`.`$t` WHERE ruta_archivo REGEXP '(uploads|manuales)/' AND ruta_archivo NOT REGEXP '(uploads|manuales)/e{$EMPRESA}/'";
    $rotas = (int)$pdo->query($sql)->fetchColumn();
    if ($rotas > 0) {
        $fallas[] = sprintf("%-24s %6d rutas de archivo SIN carpeta de empresa (uploads/e%d/ ...): backend/archivo.php les daría 404", $t, $rotas, $EMPRESA);
    }
}

echo "\n-- FALLAS (bloquean el corte) ---------------------------------------\n";
if ($fallas) {
    foreach ($fallas as $l) echo "  ❌ $l\n";
    echo "\nRESULTADO: ❌ NO PASA. Resuelve las fallas antes de tocar producción.\n";
    exit(1);
}
echo "  (ninguna)\n";
echo "\nRESULTADO: ✅ PASA. Toda tabla del origen con datos está mapeada (conteos iguales) o excluida a propósito.\n";
exit(0);
