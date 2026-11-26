<?php
// Lógica compartida del módulo de inventario. Toda modificación de existencia
// pasa por invRegistrarMovimiento(), que bloquea la fila del artículo y deja el
// movimiento en el kárdex (inventario_movimientos). Las funciones que integran
// otros módulos (incidencias y ventas) se apoyan en ella.
//
// Requiere $pdo (config/database.php) y sesión iniciada (config/auth.php).
// Ninguna función abre ni cierra transacciones: el llamador decide.
//
// Historial: cada movimiento queda en `auditoria` dentro de la misma
// transacción (si la operación se revierte, su registro también), una vez en
// el artículo (folio = SKU) y otra en la incidencia o venta que lo originó.
require_once __DIR__ . '/../../auth/audit.php';

// Catálogo único de productos y servicios (migración 007): los servicios y la
// mano de obra viven en la misma tabla para cotizar con un solo precio y costo,
// pero no llevan existencias (controla_existencia = 0): no entran al kárdex,
// a los mínimos ni al valor del inventario.
const INV_CATEGORIAS = ['Equipo', 'Refacción', 'Consumible', 'Herramienta', 'Servicio', 'Mano de obra'];
const INV_CATEGORIAS_SIN_EXISTENCIA = ['Servicio', 'Mano de obra'];

function invCategoriaControlaExistencia(string $categoria): bool {
    return !in_array($categoria, INV_CATEGORIAS_SIN_EXISTENCIA, true);
}

/** La columna controla_existencia existe (migración 007 aplicada). */
function invTieneControlExistencia(PDO $pdo): bool {
    static $existe = null;
    if ($existe === null) {
        try {
            $existe = (bool)$pdo->query("SHOW COLUMNS FROM inventario_articulos LIKE 'controla_existencia'")->fetch();
        } catch (Throwable $e) {
            $existe = false;
        }
    }
    return $existe;
}

/** La columna inventario_articulos.imagen existe (migración 008). */
function invTieneImagen(PDO $pdo): bool {
    static $existe = null;
    if ($existe === null) {
        try {
            $existe = (bool)$pdo->query("SHOW COLUMNS FROM inventario_articulos LIKE 'imagen'")->fetch();
        } catch (Throwable $e) {
            $existe = false;
        }
    }
    return $existe;
}

// Foto del producto: JPG o PNG (los que dibuja el PDF de cotización), hasta 3 MB.
const INV_IMAGEN_MAX_BYTES = 3 * 1024 * 1024;
const INV_IMAGEN_TIPOS = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png'];

/** Guarda la foto subida en uploads/e<ID>/inventario/ y devuelve su ruta relativa a la raíz. */
function invGuardarImagen(int $articuloId, array $archivo): string {
    if (($archivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($archivo['tmp_name'] ?? '')) {
        throw new InventarioException('No se recibió la imagen. Inténtalo de nuevo.');
    }
    if ($archivo['size'] > INV_IMAGEN_MAX_BYTES) throw new InventarioException('La imagen pesa más de 3 MB.');
    $info = @getimagesize($archivo['tmp_name']);
    $ext = $info ? (INV_IMAGEN_TIPOS[$info[2]] ?? null) : null;
    if (!$ext) throw new InventarioException('La imagen debe ser JPG o PNG.');
    $relativa = carpetaEmpresa('uploads') . '/inventario';
    $dir = __DIR__ . '/../../' . $relativa;
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) throw new RuntimeException('No se pudo crear la carpeta de imágenes.');
    $nombre = 'art' . $articuloId . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!move_uploaded_file($archivo['tmp_name'], "$dir/$nombre")) throw new RuntimeException('No se pudo guardar la imagen.');
    return "$relativa/$nombre";
}

/** Borra del disco una foto de producto de la empresa activa (solo dentro de su carpeta). */
function invBorrarImagen(?string $ruta): void {
    if (!$ruta || strpos($ruta, carpetaEmpresa('uploads') . '/inventario/') !== 0) return;
    $archivo = __DIR__ . '/../../' . $ruta;
    if (is_file($archivo)) @unlink($archivo);
}

/** Condición SQL para quedarse solo con productos físicos ('' sin la migración). */
function invSoloFisicos(PDO $pdo, string $alias = ''): string {
    return invTieneControlExistencia($pdo) ? " AND {$alias}controla_existencia = 1" : '';
}
const INV_TIPOS_MOVIMIENTO = ['entrada', 'salida', 'ajuste'];

class InventarioException extends RuntimeException {}

function invNumero($valor): float {
    return round((float)str_replace(',', '.', (string)$valor), 2);
}

/**
 * Aplica un cambio de existencia y lo registra en el kárdex.
 * $cantidad lleva signo: positiva entra, negativa sale.
 * Devuelve la existencia resultante. Lanza InventarioException si el
 * artículo no existe o si la salida deja la existencia en negativo.
 */
function invRegistrarMovimiento(
    PDO $pdo,
    int $articuloId,
    float $cantidad,
    string $tipo,
    ?string $motivo = null,
    ?string $refTipo = null,
    ?int $refId = null,
    ?string $refFolio = null,
    bool $permitirNegativo = false
): float {
    if (!in_array($tipo, INV_TIPOS_MOVIMIENTO, true)) {
        throw new InventarioException("Tipo de movimiento no válido: $tipo");
    }
    if (abs($cantidad) < 0.005) {
        throw new InventarioException('La cantidad del movimiento no puede ser cero.');
    }

    $control = invTieneControlExistencia($pdo) ? ', controla_existencia' : '';
    $stmt = $pdo->prepare("SELECT id, sku, nombre, existencia, costo_unitario, precio_venta, unidad$control FROM inventario_articulos WHERE id = ? FOR UPDATE");
    $stmt->execute([$articuloId]);
    $art = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$art) {
        throw new InventarioException("El artículo #$articuloId no existe.");
    }
    if (isset($art['controla_existencia']) && (int)$art['controla_existencia'] === 0) {
        throw new InventarioException("{$art['nombre']} ({$art['sku']}) es un servicio: no lleva existencias.");
    }

    $nueva = round((float)$art['existencia'] + $cantidad, 2);
    // Solo una salida puede dejar la existencia en negativo; una entrada siempre suma
    if ($cantidad < 0 && $nueva < 0 && !$permitirNegativo) {
        throw new InventarioException(sprintf(
            'Existencia insuficiente de %s (%s): hay %s %s y se requieren %s.',
            $art['nombre'], $art['sku'],
            rtrim(rtrim(number_format((float)$art['existencia'], 2, '.', ''), '0'), '.'),
            $art['unidad'],
            rtrim(rtrim(number_format(abs($cantidad), 2, '.', ''), '0'), '.')
        ));
    }

    $pdo->prepare("UPDATE inventario_articulos SET existencia = ?, actualizado_en = NOW() WHERE id = ?")
        ->execute([$nueva, $articuloId]);

    // D-51: el precio de venta se congela el día del movimiento (migración 020). Una devolución
    // de una orden o venta regresa cada pieza al precio con que salió (por capas, la última que
    // salió es la primera que regresa), y puede quedar en varios movimientos si salió a distintos
    // precios. Una salida anterior a la migración (sin precio) regresa también sin precio.
    $congelar = invTienePrecioCongelado($pdo);
    $tramos = [[$cantidad, $art['precio_venta'] !== null ? (float)$art['precio_venta'] : null, null]];
    if ($congelar && $cantidad > 0 && $refTipo !== null && $refId !== null) {
        $tramos = invTramosDevolucion($pdo, $articuloId, $refTipo, $refId, $cantidad, $art['precio_venta'] !== null ? (float)$art['precio_venta'] : null);
    }
    $existenciaTramo = (float)$art['existencia'];
    foreach ($tramos as [$cantTramo, $precioTramo, $costoTramo]) {
        $existenciaTramo = round($existenciaTramo + $cantTramo, 2);
        $valores = [
            $articuloId, $tipo, $cantTramo, $existenciaTramo, $costoTramo ?? $art['costo_unitario'], $motivo,  // H-11
            $refTipo, $refId, $refFolio,
            $_SESSION['user_id'] ?? null,
            $_SESSION['nombre'] ?? ($_SESSION['usuario'] ?? null),
        ];
        if ($congelar) $valores[] = $precioTramo;
        $pdo->prepare(
            "INSERT INTO inventario_movimientos
                (articulo_id, tipo, cantidad, existencia_resultante, costo_unitario, motivo,
                 referencia_tipo, referencia_id, referencia_folio, usuario_id, usuario_nombre" . ($congelar ? ', precio_unitario' : '') . ")
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?" . ($congelar ? ', ?' : '') . ")"
        )->execute($valores);
    }

    invAuditarMovimiento($art, (float)$art['existencia'], $nueva, $cantidad, $tipo, $motivo, $refTipo, $refId, $refFolio);

    return $nueva;
}

/**
 * D-51: reparte una devolución de $cantidad entre las capas de salida que siguen aplicadas en
 * la referencia (la última en salir es la primera en regresar). Devuelve [[cantidad, precio|null]].
 * Lo que exceda lo aplicado (no debería pasar) regresa al precio actual.
 */
function invTramosDevolucion(PDO $pdo, int $articuloId, string $refTipo, int $refId, float $cantidad, ?float $precioActual): array {
    $st = $pdo->prepare('SELECT cantidad, precio_unitario, costo_unitario FROM inventario_movimientos
                         WHERE articulo_id = ? AND referencia_tipo = ? AND referencia_id = ? ORDER BY id');
    $st->execute([$articuloId, $refTipo, $refId]);
    $capas = [];  // pila de [cantidad pendiente, precio]
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $m) {
        $c = (float)$m['cantidad'];
        $p = $m['precio_unitario'] !== null ? (float)$m['precio_unitario'] : null;
        // H-11: cada capa conserva también el costo con que salió (para la rentabilidad)
        if ($c < 0) { $capas[] = [-$c, $p, $m['costo_unitario'] !== null ? (float)$m['costo_unitario'] : null]; continue; }
        // Una devolución anterior ya consumió capas (de la más reciente a la más antigua)
        while ($c > 0.00001 && $capas) {
            $i = count($capas) - 1;
            $toma = min($c, $capas[$i][0]);
            $capas[$i][0] -= $toma;
            $c -= $toma;
            if ($capas[$i][0] <= 0.00001) array_pop($capas);
        }
    }
    $tramos = [];
    $resta = $cantidad;
    while ($resta > 0.00001 && $capas) {
        $i = count($capas) - 1;
        $toma = min($resta, $capas[$i][0]);
        $precio = $capas[$i][1];
        $costo = $capas[$i][2];
        $ult = count($tramos) - 1;
        if ($tramos && $tramos[$ult][1] === $precio && $tramos[$ult][2] === $costo) $tramos[$ult][0] = round($tramos[$ult][0] + $toma, 2);
        else $tramos[] = [round($toma, 2), $precio, $costo];
        $capas[$i][0] -= $toma;
        $resta -= $toma;
        if ($capas[$i][0] <= 0.00001) array_pop($capas);
    }
    if ($resta > 0.00001) $tramos[] = [round($resta, 2), $precioActual, null];
    return $tramos;
}

/** ¿Ya se aplicó la migración 020 (precio congelado de los movimientos; D-51)? */
function invTienePrecioCongelado(PDO $pdo): bool {
    static $cache = [];
    $clave = EmpresaContexto::$id ?? 0;
    if (!isset($cache[$clave])) {
        try { $cache[$clave] = (bool)$pdo->query("SHOW COLUMNS FROM inventario_movimientos LIKE 'precio_unitario'")->fetch(); } catch (Throwable $e) { $cache[$clave] = false; }
    }
    return $cache[$clave];
}

/** ¿Ya se aplicó la migración 024 (moneda por artículo)? */
function invTieneMoneda(PDO $pdo): bool {
    static $cache = [];
    $clave = EmpresaContexto::$id ?? 0;
    if (!isset($cache[$clave])) {
        try { $cache[$clave] = (bool)$pdo->query("SHOW COLUMNS FROM inventario_articulos LIKE 'moneda'")->fetch(); } catch (Throwable $e) { $cache[$clave] = false; }
    }
    return $cache[$clave];
}

/** ¿Ya se aplicó la migración 024 (tasa de IVA por artículo)? */
function invTieneTasaIva(PDO $pdo): bool {
    static $cache = [];
    $clave = EmpresaContexto::$id ?? 0;
    if (!isset($cache[$clave])) {
        try { $cache[$clave] = (bool)$pdo->query("SHOW COLUMNS FROM inventario_articulos LIKE 'tasa_iva'")->fetch(); } catch (Throwable $e) { $cache[$clave] = false; }
    }
    return $cache[$clave];
}

/** ¿Ya se aplicó la migración 018 (serie de refacciones; D-45)? */
function invTieneSeries(PDO $pdo): bool {
    static $cache = [];
    $clave = EmpresaContexto::$id ?? 0;
    if (!isset($cache[$clave])) {
        try { $cache[$clave] = (bool)$pdo->query("SHOW COLUMNS FROM inventario_articulos LIKE 'controla_serie'")->fetch(); } catch (Throwable $e) { $cache[$clave] = false; }
    }
    return $cache[$clave];
}

/**
 * D-45: si el artículo controla serie, exige un N/S por pieza (cantidad entera, sin repetir)
 * y devuelve las series ya limpias; si no, devuelve [].
 */
function invSeriesExigidas(PDO $pdo, int $articuloId, float $cantidad, $series): array {
    if (!invTieneSeries($pdo)) return [];
    $st = $pdo->prepare('SELECT nombre, controla_serie FROM inventario_articulos WHERE id = ?');
    $st->execute([$articuloId]);
    $art = $st->fetch(PDO::FETCH_ASSOC);
    if (!$art || (int)$art['controla_serie'] !== 1) return [];
    if (abs($cantidad - round($cantidad)) > 0.0001) throw new InventarioException("{$art['nombre']} se registra por pieza con su número de serie: la cantidad debe ser entera.");
    $limpias = array_values(array_filter(array_map(fn($s) => mb_substr(trim((string)$s), 0, 60), is_array($series) ? $series : []), fn($s) => $s !== ''));
    if (count($limpias) !== (int)round($cantidad)) {
        throw new InventarioException("{$art['nombre']} controla número de serie: captura el N/S de cada pieza (" . (int)round($cantidad) . ').');
    }
    if (count(array_unique(array_map('mb_strtoupper', $limpias))) !== count($limpias)) throw new InventarioException('Hay números de serie repetidos.');
    return $limpias;
}

// Acciones del historial para movimientos de inventario
const INV_ACCION_HISTORIAL = ['entrada' => 'ENTRADA', 'salida' => 'SALIDA', 'ajuste' => 'AJUSTE'];
const INV_TABLA_REFERENCIA = ['incidencia' => 'incidencias', 'venta' => 'ventas'];

function invAuditarMovimiento(
    array $art, float $anterior, float $nueva, float $cantidad, string $tipo,
    ?string $motivo, ?string $refTipo, ?int $refId, ?string $refFolio
): void {
    $etiquetaRef = null;
    if ($refTipo && $refTipo !== 'manual' && $refId) {
        $etiquetaRef = ($refTipo === 'venta' ? 'Venta ' : 'Orden de servicio ') . ($refFolio ?: "#$refId");
    }
    $base = ['sku' => $art['sku'], 'articulo' => $art['nombre']];

    // 1) Historial del artículo: la existencia antes y después del movimiento
    registrarAuditoria(
        'inventario_articulos', (int)$art['id'], INV_ACCION_HISTORIAL[$tipo],
        $base + ['existencia' => $anterior . ' ' . $art['unidad']],
        $base + [
            'existencia' => $nueva . ' ' . $art['unidad'],
            'movimiento' => ucfirst($tipo),
            'cantidad'   => ($cantidad > 0 ? '+' : '') . $cantidad . ' ' . $art['unidad'],
            'motivo'     => $motivo,
        ] + ($etiquetaRef ? ['referencia' => $etiquetaRef] : []),
        $art['sku']
    );

    // 2) Historial del documento que lo originó (incidencia o venta)
    if ($etiquetaRef && isset(INV_TABLA_REFERENCIA[$refTipo])) {
        registrarAuditoria(
            INV_TABLA_REFERENCIA[$refTipo], $refId, 'INVENTARIO', null,
            $base + [
                'movimiento'            => $cantidad < 0 ? 'Salida de almacén' : 'Devolución a almacén',
                'cantidad'              => abs($cantidad) . ' ' . $art['unidad'],
                'existencia_resultante' => $nueva . ' ' . $art['unidad'],
                'motivo'                => $motivo,
            ],
            $refFolio
        );
    }
}

/**
 * Unidades netas que una referencia (incidencia/venta) ha sacado de cada
 * artículo, según el kárdex: [articulo_id => cantidad positiva consumida].
 */
function invConsumoPorReferencia(PDO $pdo, string $refTipo, int $refId): array {
    $stmt = $pdo->prepare(
        "SELECT articulo_id, -SUM(cantidad) AS consumido
         FROM inventario_movimientos
         WHERE referencia_tipo = ? AND referencia_id = ?
         GROUP BY articulo_id
         ORDER BY articulo_id"
    );
    $stmt->execute([$refTipo, $refId]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $f) {
        $out[(int)$f['articulo_id']] = round((float)$f['consumido'], 2);
    }
    return $out;
}

/**
 * Concilia la existencia con lo que la venta tiene registrado: cada fila de
 * venta_detalles con articulo_id equivale a una unidad vendida. Compara con lo
 * ya descontado para esa venta y registra solo la diferencia, así cubre alta,
 * edición (más o menos series, cambio de artículo) y eliminación (si ya no hay
 * detalles, devuelve todo). Llamar dentro de la transacción de la venta.
 */
function invSincronizarVenta(PDO $pdo, int $ventaId, ?string $folio = null): void {
    $stmt = $pdo->prepare(
        "SELECT articulo_id, COUNT(*) AS unidades
         FROM venta_detalles
         WHERE venta_id = ? AND articulo_id IS NOT NULL
         GROUP BY articulo_id"
    );
    $stmt->execute([$ventaId]);
    $deseado = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $f) {
        $deseado[(int)$f['articulo_id']] = (float)$f['unidades'];
    }

    $actual = invConsumoPorReferencia($pdo, 'venta', $ventaId);
    $etiqueta = $folio ? "Venta $folio" : "Venta #$ventaId";

    $articulos = array_unique(array_merge(array_keys($deseado), array_keys($actual)));
    sort($articulos, SORT_NUMERIC);  // bloqueos en orden ascendente (evita interbloqueos)
    foreach ($articulos as $articuloId) {
        $diferencia = round(($deseado[$articuloId] ?? 0) - ($actual[$articuloId] ?? 0), 2);
        if (abs($diferencia) < 0.005) continue;
        // diferencia > 0: faltan unidades por descontar; < 0: hay que devolver
        invRegistrarMovimiento(
            $pdo, $articuloId, -$diferencia,
            $diferencia > 0 ? 'salida' : 'entrada',
            $diferencia > 0 ? $etiqueta : ($deseado ? "Devolución por ajuste de $etiqueta" : "Devolución por cancelación de $etiqueta"),
            'venta', $ventaId, $folio
        );
    }
}

/**
 * Regresa al inventario todo lo consumido por una incidencia (al eliminarla).
 */
function invRevertirIncidencia(PDO $pdo, int $incidenciaId, ?string $folio = null): void {
    foreach (invConsumoPorReferencia($pdo, 'incidencia', $incidenciaId) as $articuloId => $consumido) {
        if ($consumido <= 0) continue;
        invRegistrarMovimiento(
            $pdo, $articuloId, $consumido, 'entrada',
            'Devolución por eliminación de la orden de servicio ' . ($folio ?: "#$incidenciaId"),
            'incidencia', $incidenciaId, $folio
        );
    }
}

function invTablasDisponibles(PDO $pdo): bool {
    static $ok = null;
    if ($ok === null) {
        try {
            $ok = (bool)$pdo->query("SHOW TABLES LIKE 'inventario_movimientos'")->fetchColumn();
        } catch (\Throwable $e) {
            $ok = false;
        }
    }
    return $ok;
}

// ---------------------------------------------------------------- conceptos aplicados (D-23)
// En una orden de servicio se aplican tres clases de conceptos:
//  - refacción o consumible: salida de inventario (invRegistrarMovimiento);
//  - servicio o mano de obra: sin existencias, en incidencia_servicios;
//  - equipo de reemplazo: salida de inventario y alta en el padrón del cliente.

function invServiciosDisponibles(PDO $pdo): bool {
    static $hay = null;
    if ($hay === null) {
        try {
            $hay = (bool)$pdo->query("SHOW TABLES LIKE 'incidencia_servicios'")->fetch();
        } catch (Throwable $e) {
            $hay = false;
        }
    }
    return $hay;
}

function invExigirServicios(PDO $pdo): void {
    if (!invServiciosDisponibles($pdo)) {
        throw new InventarioException('Falta aplicar la actualización 015 de la base de datos para registrar servicios y equipos de reemplazo. Avisa al administrador del sistema.');
    }
}

/** Aplica un servicio o mano de obra a la orden. Llamar dentro de una transacción con la orden bloqueada. */
function invAplicarServicio(PDO $pdo, int $incidenciaId, int $articuloId, float $cantidad, ?int $uid, ?string $unombre): int {
    invExigirServicios($pdo);
    if ($cantidad <= 0) throw new InventarioException('La cantidad debe ser mayor a cero.');
    $st = $pdo->prepare('SELECT id, nombre, categoria, unidad, precio_venta, activo FROM inventario_articulos WHERE id = ?');
    $st->execute([$articuloId]);
    $art = $st->fetch(PDO::FETCH_ASSOC);
    if (!$art || !(int)$art['activo']) throw new InventarioException('El servicio no existe en el catálogo.');
    if (invCategoriaControlaExistencia($art['categoria'])) {
        throw new InventarioException("{$art['nombre']} lleva existencias: regístralo como refacción o equipo, no como servicio.");
    }
    $pdo->prepare('INSERT INTO incidencia_servicios (incidencia_id, articulo_id, descripcion, unidad, cantidad, precio_unitario, creado_por, creado_por_nombre)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$incidenciaId, $articuloId, mb_substr($art['nombre'], 0, 255), $art['unidad'] ?: 'servicio', $cantidad, (float)$art['precio_venta'], $uid, $unombre]);
    return (int)$pdo->lastInsertId();
}

/** Servicios aplicados en la orden. */
function invServiciosDeIncidencia(PDO $pdo, int $incidenciaId): array {
    if (!invServiciosDisponibles($pdo)) return [];
    $st = $pdo->prepare('SELECT id, articulo_id, descripcion, unidad, cantidad, precio_unitario, creado_por_nombre, creado_en
                         FROM incidencia_servicios WHERE incidencia_id = ? ORDER BY id');
    $st->execute([$incidenciaId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Equipo de reemplazo: da de alta en el padrón del cliente el equipo que se instaló
 * desde la orden. Exige serie (que no esté ya en el padrón) y una sola pieza. Llamar
 * dentro de la transacción del consumo, con la orden bloqueada.
 */
function invRegistrarReemplazo(PDO $pdo, int $incidenciaId, int $articuloId, float $cantidad, array $datos): int {
    invExigirServicios($pdo);
    if (abs($cantidad - 1) > 0.0001) throw new InventarioException('Un equipo de reemplazo se registra de uno en uno (cada uno con su serie).');
    $serie = trim((string)($datos['numero_serie'] ?? ''));
    if ($serie === '' || in_array(strtoupper($serie), ['S/N', 'N/A'], true)) {
        throw new InventarioException('Escribe el número de serie del equipo de reemplazo: con él queda en el padrón del cliente.');
    }
    $st = $pdo->prepare('SELECT cliente, sucursal FROM padron_equipos WHERE numero_serie = ? LIMIT 1');
    $st->execute([$serie]);
    if ($otro = $st->fetch(PDO::FETCH_ASSOC)) {
        throw new InventarioException("La serie $serie ya está en el padrón (equipo de {$otro['cliente']}).");
    }
    $st = $pdo->prepare('SELECT cliente, sucursal FROM incidencias WHERE id = ?');
    $st->execute([$incidenciaId]);
    $inc = $st->fetch(PDO::FETCH_ASSOC);
    $st = $pdo->prepare('SELECT nombre, categoria, marca, modelo FROM inventario_articulos WHERE id = ?');
    $st->execute([$articuloId]);
    $art = $st->fetch(PDO::FETCH_ASSOC);
    if (!$inc || !$art) throw new InventarioException('La orden de servicio o el artículo no existen.');
    if ($art['categoria'] !== 'Equipo') throw new InventarioException('Solo un artículo de la categoría Equipo se registra como equipo de reemplazo.');
    $garantia = max(0, (int)($datos['garantia'] ?? 0));
    $pdo->prepare("INSERT INTO padron_equipos (cliente, sucursal, equipo, marca, modelo, numero_serie, garantia, origen, fecha_registro, reemplazo_incidencia_id, reemplazo_articulo_id)
                   VALUES (?, ?, ?, ?, ?, ?, ?, 'Reemplazo', CURDATE(), ?, ?)")
        ->execute([$inc['cliente'], $inc['sucursal'], $art['nombre'], $art['marca'], $art['modelo'], $serie, $garantia, $incidenciaId, $articuloId]);
    $nuevoEquipo = (int)$pdo->lastInsertId();
    require_once __DIR__ . '/sucursales_helpers.php';
    sucLigarRegistro($pdo, 'padron_equipos', $nuevoEquipo);  // D-44
    return $nuevoEquipo;
}

/** Equipos de reemplazo instalados desde la orden (en el padrón). */
function invReemplazosDeIncidencia(PDO $pdo, int $incidenciaId, ?int $articuloId = null): array {
    if (!invServiciosDisponibles($pdo)) return [];
    $sql = 'SELECT id, reemplazo_articulo_id AS articulo_id, equipo, marca, modelo, numero_serie FROM padron_equipos WHERE reemplazo_incidencia_id = ?';
    $params = [$incidenciaId];
    if ($articuloId !== null) { $sql .= ' AND reemplazo_articulo_id = ?'; $params[] = $articuloId; }
    $st = $pdo->prepare($sql . ' ORDER BY id');
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** Al eliminar la orden: sus servicios y los equipos de reemplazo que dio de alta en el padrón. */
function invQuitarConceptosIncidencia(PDO $pdo, int $incidenciaId): void {
    if (!invServiciosDisponibles($pdo)) return;
    $pdo->prepare('DELETE FROM incidencia_servicios WHERE incidencia_id = ?')->execute([$incidenciaId]);
    // L-29: si otra orden atendió ese equipo, conserva la serie como texto, sin liga colgando
    try {
        $pdo->prepare('UPDATE incidencia_equipos ie JOIN padron_equipos p ON p.id = ie.padron_id SET ie.padron_id = NULL
                       WHERE p.reemplazo_incidencia_id = ? AND ie.incidencia_id <> ?')->execute([$incidenciaId, $incidenciaId]);
    } catch (PDOException $e) { /* sin la migración 017 */ }
    $pdo->prepare('DELETE FROM padron_equipos WHERE reemplazo_incidencia_id = ?')->execute([$incidenciaId]);
    // D-41: la liga con los equipos atendidos (los equipos siguen en el padrón)
    try { $pdo->prepare('DELETE FROM incidencia_equipos WHERE incidencia_id = ?')->execute([$incidenciaId]); } catch (PDOException $e) { /* sin la migración 017 */ }
}
