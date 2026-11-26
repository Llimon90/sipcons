<?php
/**
 * Migración de datos: SIPCONS (una sola empresa, tablas sin prefijo) ->
 * Servidesk (empresa 1, tablas con prefijo e1_).
 *
 * Mueve los datos reales de producción de SIPCONS a la estructura de Servidesk,
 * conservando los IDs (para que las relaciones incidencia/venta/cliente/padrón
 * sigan cuadrando) y la numeración de folios (SIP-#### continúa sin chocar).
 *
 * NO es un endpoint ni toca la app: es una herramienta de línea de comandos que
 * se corre UNA vez por base, igual que los otros scripts de database/tools/.
 *
 * Es resistente a la versión del esquema destino: antes de cada INSERT consulta
 * las columnas que REALMENTE existen en la tabla destino y solo inserta esas
 * (el schema.sql de una instalación nueva tiene menos columnas que la plantilla
 * ya migrada: p. ej. sucursal_id/limite_credito las agregan migraciones de
 * Servidesk más tarde, con default, así que omitirlas aquí es seguro).
 *
 * ── Qué asume ───────────────────────────────────────────────────────────────
 *  - ORIGEN: esquema viejo de SIPCONS con datos reales (migraciones 001-014
 *    aplicadas: reporte_cliente / categoria / numero_serie).
 *  - DESTINO: esquema de Servidesk cargado con database/schema.sql (las tablas
 *    e1_* vacías; schema.sql crea sola la empresa 1 'demo', que este script
 *    convierte en la empresa de SIPCONS). Cargar SIN seed.sql.
 *  - Ambas bases en el mismo servidor MySQL.
 *
 * ── Cómo se corre ───────────────────────────────────────────────────────────
 *    C:\xampp\php\php.exe database\tools\migrar_sipcons_a_servidesk.php \
 *        --origen=sipcons_old --destino=servidesk_prod [--prefijo-folio=SIP] \
 *        [--migrar-auditoria] [--force]
 *
 * El repo es PÚBLICO: este archivo NO contiene datos de clientes; todo sale de
 * la base origen en tiempo de ejecución.
 */

declare(strict_types=1);
mb_internal_encoding('UTF-8');

$opts = getopt('', ['origen:', 'destino:', 'host:', 'user:', 'pass:', 'empresa:', 'prefijo-folio:', 'force', 'migrar-auditoria']);
$ORIGEN   = $opts['origen']  ?? null;
$DESTINO  = $opts['destino'] ?? null;
$HOST     = $opts['host']    ?? '127.0.0.1';
$USER     = $opts['user']    ?? 'root';
$PASS     = $opts['pass']    ?? '';
$EMPRESA  = (int)($opts['empresa'] ?? 1);
$FORCE    = isset($opts['force']);
$MIG_AUD  = isset($opts['migrar-auditoria']);
$PREF_OVERRIDE = $opts['prefijo-folio'] ?? null;
$P = 'e' . $EMPRESA . '_';

if (!$ORIGEN || !$DESTINO) {
    fwrite(STDERR, "Faltan --origen y/o --destino.\n");
    fwrite(STDERR, "Ej: php database/tools/migrar_sipcons_a_servidesk.php --origen=sipcons_old --destino=servidesk_prod\n");
    exit(1);
}

try {
    $pdo = new PDO("mysql:host=$HOST;charset=utf8mb4", $USER, $PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    $pdo->exec('SET NAMES utf8mb4');
} catch (PDOException $e) {
    fwrite(STDERR, 'No se pudo conectar a MySQL: ' . $e->getMessage() . "\n");
    exit(1);
}

$o  = fn(string $t): string => "`$ORIGEN`.`$t`";
$d  = fn(string $t): string => "`$DESTINO`.`$P$t`";
$dc = fn(string $t): string => "`$DESTINO`.`$t`";

function existeTabla(PDO $pdo, string $base, string $tabla): bool {
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = ?');
    $st->execute([$base, $tabla]);
    return (bool)$st->fetchColumn();
}
function columnas(PDO $pdo, string $base, string $tabla): array {
    $st = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.columns WHERE table_schema = ? AND table_name = ?');
    $st->execute([$base, $tabla]);
    return array_column($st->fetchAll(), 'COLUMN_NAME');
}
function cuenta(PDO $pdo, string $base, string $tabla): int {
    return existeTabla($pdo, $base, $tabla) ? (int)$pdo->query("SELECT COUNT(*) FROM `$base`.`$tabla`")->fetchColumn() : -1;
}

/**
 * INSERT..SELECT filtrando a las columnas que existen en la tabla destino.
 *  $map: [ columnaDestino => expresiónSQL sobre el SELECT del origen ].
 * Solo se insertan las columnas de $map que existan de verdad en el destino.
 */
function migrar(PDO $pdo, string $titulo, string $destBase, string $destTabla, string $fromSql, array $map): void {
    $existentes = columnas($pdo, $destBase, $destTabla);
    $cols = []; $exprs = [];
    foreach ($map as $col => $expr) {
        if (in_array($col, $existentes, true)) { $cols[] = "`$col`"; $exprs[] = "$expr AS `$col`"; }
    }
    if (!$cols) { echo "   $titulo: (sin columnas coincidentes, omitido)\n"; return; }
    $sql = "INSERT INTO `$destBase`.`$destTabla` (" . implode(', ', $cols) . ") SELECT " . implode(', ', $exprs) . " FROM $fromSql";
    $n = $pdo->exec($sql);
    echo "   $titulo: $n filas\n";
}

foreach ([$ORIGEN, $DESTINO] as $b) {
    $hay = $pdo->prepare('SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = ?');
    $hay->execute([$b]);
    if (!$hay->fetchColumn()) { fwrite(STDERR, "La base '$b' no existe.\n"); exit(1); }
}
if (!existeTabla($pdo, $DESTINO, "{$P}incidencias")) {
    fwrite(STDERR, "El destino '$DESTINO' no tiene las tablas de Servidesk ({$P}incidencias). Carga database/schema.sql primero.\n");
    exit(1);
}
if (!$FORCE && cuenta($pdo, $DESTINO, "{$P}incidencias") > 0) {
    fwrite(STDERR, "El destino ya tiene datos en {$P}incidencias. Carga una base limpia (schema.sql sin seed) o usa --force.\n");
    exit(1);
}

echo "== Migración SIPCONS -> Servidesk ==\n";
echo "   Origen : $ORIGEN\n   Destino: $DESTINO (empresa $EMPRESA, prefijo $P)\n\n";

// Prefijo de folio: el indicado, o el de los folios existentes (SIP-0001 -> SIP), o SIP.
$prefijoFolio = $PREF_OVERRIDE;
if (!$prefijoFolio) {
    $f = $pdo->query("SELECT numero_incidente FROM {$o('incidencias')} WHERE numero_incidente IS NOT NULL AND numero_incidente <> '' ORDER BY id DESC LIMIT 1")->fetchColumn();
    if ($f && preg_match('/^([A-Za-z]+)/', (string)$f, $m)) $prefijoFolio = strtoupper($m[1]);
}
$prefijoFolio = $prefijoFolio ?: 'SIP';
echo "   Prefijo de folio: $prefijoFolio\n\n";

$categorias = $pdo->query("SELECT DISTINCT categoria FROM {$o('incidencias')} WHERE categoria IS NOT NULL AND categoria <> '' ORDER BY categoria")->fetchAll(PDO::FETCH_COLUMN);

// Roles viejos de SIPCONS -> roles de Servidesk
$mapRol = "CASE rol
             WHEN 'Administrador' THEN 'Administrador'
             WHEN 'Supervisor' THEN 'Supervisor'
             WHEN 'Administrativo' THEN 'Administrativo'
             WHEN 'Técnico' THEN 'Técnico'
             WHEN 'Programador' THEN 'Administrador'
             WHEN 'Técnico/Administrador' THEN 'Administrador'
             WHEN 'Técnico/Administrativo' THEN 'Supervisor'
             ELSE 'Técnico' END";

$pdo->beginTransaction();
try {
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

    // 0. Empresa: schema.sql ya creó la empresa 1 ('demo'); la volvemos la de SIPCONS.
    $marca = json_encode([
        'nombre' => 'SIPCONS', 'prefijo_folio' => $prefijoFolio,
        'categorias' => array_values($categorias), 'zona_horaria' => 'America/Tijuana',
    ], JSON_UNESCAPED_UNICODE);
    $pdo->prepare(
        "INSERT INTO {$dc('empresas')} (id, slug, nombre, estado, marca, creado_en)
         VALUES (?, 'sipcons', 'SIPCONS', 'activa', ?, NOW())
         ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), estado = VALUES(estado), marca = VALUES(marca)"
    )->execute([$EMPRESA, $marca]);
    echo "0. empresa $EMPRESA lista (estado activa, marca SIPCONS).\n";

    echo "1. Catálogos y clientes\n";
    if (existeTabla($pdo, $ORIGEN, 'marcas'))
        migrar($pdo, 'marcas', $DESTINO, "{$P}marcas", $o('marcas'),
            ['id' => 'id', 'nombre' => 'nombre', 'activo' => 'COALESCE(activo,1)']);
    if (existeTabla($pdo, $ORIGEN, 'modelos'))
        migrar($pdo, 'modelos', $DESTINO, "{$P}modelos", $o('modelos'),
            ['id' => 'id', 'marca_id' => 'marca_id', 'nombre' => 'nombre',
             'tipo_equipo' => "COALESCE(NULLIF(tipo_equipo,''),'General')", 'activo' => 'COALESCE(activo,1)']);
    if (existeTabla($pdo, $ORIGEN, 'documentos'))
        migrar($pdo, 'documentos', $DESTINO, "{$P}documentos", $o('documentos'), [
            'id' => 'id', 'modelo_id' => 'modelo_id',
            'tipo_documento' => "CASE LOWER(COALESCE(tipo_documento,''))
                WHEN 'manual' THEN 'manual' WHEN 'lista_partes' THEN 'lista_partes' WHEN 'tutorial' THEN 'tutorial'
                WHEN 'imagen' THEN 'imagen' WHEN 'diagrama' THEN 'diagrama' WHEN 'esquema' THEN 'esquema'
                WHEN 'firmware' THEN 'firmware' WHEN 'software' THEN 'software' ELSE 'otro' END",
            'nombre_archivo' => "COALESCE(NULLIF(nombre_archivo,''),'documento')", 'descripcion' => 'descripcion',
            'ruta_archivo' => "COALESCE(ruta_archivo,'')", 'fecha_subida' => 'COALESCE(subido_en, NOW())']);
    migrar($pdo, 'clientes', $DESTINO, "{$P}clientes", $o('clientes'), [
        'id' => 'id', 'nombre' => 'nombre', 'rfc' => "COALESCE(rfc,'')", 'direccion' => "COALESCE(direccion,'')",
        'telefono' => "COALESCE(telefono,'')", 'contactos' => "COALESCE(contactos,'')", 'email' => "COALESCE(email,'')",
        'fecha_registro' => 'NOW()', 'dias_credito' => '0', 'limite_credito' => 'NULL']);
    if (existeTabla($pdo, $ORIGEN, 'cliente_contactos'))
        migrar($pdo, 'cliente_contactos', $DESTINO, "{$P}cliente_contactos", $o('cliente_contactos'),
            ['id' => 'id', 'cliente_id' => 'cliente_id', 'nombre' => 'nombre', 'telefono' => 'telefono', 'email' => 'email']);

    echo "2. Incidencias (órdenes de servicio) y archivos\n";
    migrar($pdo, 'incidencias', $DESTINO, "{$P}incidencias", $o('incidencias'), [
        'id' => 'id', 'reporte_cliente' => 'reporte_cliente', 'numero_incidente' => 'numero_incidente',
        'cliente' => 'cliente', 'contacto' => 'contacto', 'sucursal' => 'sucursal', 'sucursal_id' => 'NULL',
        'fecha' => 'fecha', 'tecnico' => 'tecnico', 'falla' => 'falla', 'categoria' => "COALESCE(categoria,'')",
        'numero_serie' => 'numero_serie', 'estatus' => 'estatus', 'motivo_estatus' => 'NULL',
        'accion' => 'accion', 'notas' => 'notas', 'clave_envio' => 'NULL']);
    if (existeTabla($pdo, $ORIGEN, 'archivos_incidencias'))
        migrar($pdo, 'archivos_incidencias', $DESTINO, "{$P}archivos_incidencias", $o('archivos_incidencias'),
            ['id' => 'id', 'incidencia_id' => 'incidencia_id', 'ruta_archivo' => 'ruta_archivo']);

    echo "3. Ventas, detalles y archivos\n";
    migrar($pdo, 'ventas', $DESTINO, "{$P}ventas", $o('ventas'), [
        'id' => 'id', 'folio' => 'folio', 'cliente' => 'cliente',
        'fecha_registro' => 'COALESCE(fecha_registro, CURDATE())', 'fecha_actualizacion' => 'DATE(fecha_actualizacion)',
        'sucursal' => "COALESCE(sucursal,'')", 'sucursal_id' => 'NULL']);
    if (existeTabla($pdo, $ORIGEN, 'venta_detalles'))
        migrar($pdo, 'venta_detalles', $DESTINO, "{$P}venta_detalles", $o('venta_detalles'), [
            'id' => 'id', 'venta_id' => 'venta_id', 'articulo_id' => 'NULL', 'equipo' => 'equipo', 'marca' => 'marca',
            'modelo' => 'modelo', 'numero_serie' => "COALESCE(numero_serie,'')", 'garantia' => 'garantia',
            'calibracion' => 'COALESCE(calibracion,0)', 'servicio' => 'COALESCE(servicio,0)',
            'frecuencia_servicio' => 'COALESCE(frecuencia_servicio,0)', 'notas' => 'notas',
            'proxima_calibracion' => 'proxima_calibracion', 'proximo_servicio' => 'proximo_servicio']);
    if (existeTabla($pdo, $ORIGEN, 'venta_archivos'))
        migrar($pdo, 'venta_archivos', $DESTINO, "{$P}venta_archivos", $o('venta_archivos'),
            ['id' => 'id', 'venta_id' => 'venta_id', 'ruta_archivo' => "COALESCE(ruta_archivo,'')",
             'nombre_archivo' => 'nombre_archivo', 'tipo_archivo' => 'tipo_archivo']);

    echo "4. Padrón de equipos\n";
    migrar($pdo, 'padron_equipos', $DESTINO, "{$P}padron_equipos", $o('padron_equipos'), [
        'id' => 'id', 'cliente' => 'cliente', 'sucursal' => 'sucursal', 'sucursal_id' => 'NULL',
        'equipo' => "COALESCE(NULLIF(equipo,''),'Equipo')", 'marca' => 'marca', 'modelo' => 'modelo',
        'numero_serie' => 'numero_serie', 'calibracion' => 'COALESCE(calibracion,0)', 'servicio' => 'COALESCE(servicio,0)',
        'frecuencia_servicio' => 'COALESCE(frecuencia_servicio,0)', 'garantia' => 'COALESCE(garantia,0)',
        'proxima_calibracion' => 'proxima_calibracion', 'proximo_servicio' => 'proximo_servicio',
        'origen' => "CASE WHEN origen = 'Venta SIPCONS' THEN 'Venta propia' ELSE 'Externo' END",
        'venta_id' => 'venta_id', 'venta_detalle_id' => 'venta_detalle_id', 'fecha_registro' => 'fecha_registro',
        'reemplazo_incidencia_id' => 'NULL', 'reemplazo_articulo_id' => 'NULL']);

    echo "5. Usuarios (tabla central con empresa_id) y permisos\n";
    $sinMapear = $pdo->query("SELECT DISTINCT rol FROM {$o('usuarios')}
        WHERE rol NOT IN ('Administrador','Supervisor','Administrativo','Técnico','Programador','Técnico/Administrador','Técnico/Administrativo')")->fetchAll(PDO::FETCH_COLUMN);
    if ($sinMapear) echo "   AVISO: roles no reconocidos (van a 'Técnico'): " . implode(', ', $sinMapear) . "\n";
    migrar($pdo, 'usuarios', $DESTINO, 'usuarios', $o('usuarios'), [
        'id' => 'id', 'empresa_id' => (string)$EMPRESA, 'nombre' => 'nombre', 'alias_tecnico' => 'alias_tecnico',
        'correo' => "COALESCE(correo,'')", 'telefono' => "COALESCE(telefono,'')", 'usuario' => 'usuario',
        'password' => 'password', 'rol' => $mapRol, 'tema' => "COALESCE(NULLIF(tema,''),'claro')",
        'puede_cerrar' => '1', 'puede_autorizar_descuentos' => '0', 'debe_cambiar_password' => '0',
        'fecha_creacion' => 'NOW()']);
    // permisos_rol: el destino ya trae los defaults de Servidesk; INSERT IGNORE no los pisa.
    // Migramos los del origen solo donde no haya ya una regla (para no perder un permiso explícito que falte).
    if (existeTabla($pdo, $ORIGEN, 'permisos_rol')) {
        $n = $pdo->exec("INSERT IGNORE INTO {$d('permisos_rol')} (rol, modulo, permitido)
            SELECT $mapRol AS rol, modulo, MAX(permitido) AS permitido
            FROM {$o('permisos_rol')}
            WHERE modulo IN ('incidencias','reportes','clientes','ventas','inventario','usuarios','soporte','informes','historial','cotizaciones','finanzas')
            GROUP BY $mapRol, modulo");
        echo "   permisos_rol (añadidos sin pisar defaults): $n filas\n";
    }

    if ($MIG_AUD && existeTabla($pdo, $ORIGEN, 'auditoria')) {
        echo "6. Auditoría histórica\n";
        $tieneFolio = in_array('registro_folio', columnas($pdo, $ORIGEN, 'auditoria'), true);
        migrar($pdo, 'auditoria', $DESTINO, "{$P}auditoria", $o('auditoria'), [
            'id' => 'id', 'usuario_id' => 'usuario_id', 'usuario_nombre' => 'usuario_nombre', 'usuario_rol' => 'usuario_rol',
            'accion' => 'accion', 'tabla' => 'tabla', 'registro_id' => 'registro_id',
            'registro_folio' => $tieneFolio ? 'registro_folio' : 'NULL',
            'datos_anteriores' => 'datos_anteriores', 'datos_nuevos' => 'datos_nuevos',
            'ip_address' => 'ip_address', 'creado_en' => 'creado_en']);
    }

    echo "7. Inicializar contadores de folio (e1_folios)\n";
    $maxInc = (int)$pdo->query("SELECT COALESCE(MAX(CAST(REGEXP_REPLACE(numero_incidente, '^.*[^0-9]', '') AS UNSIGNED)),0) FROM {$d('incidencias')} WHERE numero_incidente REGEXP '[0-9]+\$'")->fetchColumn();
    $maxVen = (int)$pdo->query("SELECT COALESCE(MAX(CAST(REGEXP_REPLACE(folio, '^.*[^0-9]', '') AS UNSIGNED)),0) FROM {$d('ventas')} WHERE folio REGEXP '[0-9]+\$'")->fetchColumn();
    $pdo->prepare("INSERT INTO {$d('folios')} (clave, ultimo) VALUES ('incidencias', ?) ON DUPLICATE KEY UPDATE ultimo = GREATEST(ultimo, VALUES(ultimo))")->execute([$maxInc]);
    $pdo->prepare("INSERT INTO {$d('folios')} (clave, ultimo) VALUES ('ventas', ?) ON DUPLICATE KEY UPDATE ultimo = GREATEST(ultimo, VALUES(ultimo))")->execute([$maxVen]);
    echo "   folios: incidencias.ultimo=$maxInc, ventas.ultimo=$maxVen\n";

    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    $pdo->commit();
    echo "\nOK: migración aplicada (commit).\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, "\nERROR, se revirtió todo (rollback): " . $e->getMessage() . "\n");
    exit(1);
}

echo "\n== Verificación (origen -> destino) ==\n";
$pares = [
    ['clientes', "{$P}clientes"], ['cliente_contactos', "{$P}cliente_contactos"],
    ['incidencias', "{$P}incidencias"], ['archivos_incidencias', "{$P}archivos_incidencias"],
    ['ventas', "{$P}ventas"], ['venta_detalles', "{$P}venta_detalles"], ['venta_archivos', "{$P}venta_archivos"],
    ['padron_equipos', "{$P}padron_equipos"], ['marcas', "{$P}marcas"], ['modelos', "{$P}modelos"],
    ['documentos', "{$P}documentos"], ['usuarios', 'usuarios'],
];
$problemas = 0;
foreach ($pares as [$src, $dst]) {
    $co = cuenta($pdo, $ORIGEN, $src);
    $cd = $dst === 'usuarios'
        ? (int)$pdo->query("SELECT COUNT(*) FROM {$dc('usuarios')} WHERE empresa_id = $EMPRESA")->fetchColumn()
        : cuenta($pdo, $DESTINO, $dst);
    if ($co < 0) { printf("   %-22s (no existe en origen)\n", $src); continue; }
    if ($co !== $cd) $problemas++;
    printf("   %-22s %5d -> %-5d %s\n", $src, $co, $cd, $co === $cd ? 'OK' : '*** DIFERENTE ***');
}
echo $problemas === 0 ? "\nTodos los conteos cuadran.\n" : "\nHay $problemas tabla(s) con conteo distinto: revisar.\n";
exit($problemas === 0 ? 0 : 2);
