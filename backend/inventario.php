<?php
// API del módulo de inventario. Un solo endpoint con ?accion=...
//
//   GET  listar               artículos (filtros: buscar, categoria, bajo_minimo, inactivos)
//   GET  resumen              KPIs, consumo mensual y artículos más usados
//   GET  kardex&id=           movimientos de un artículo
//   GET  opciones             lista ligera para selects (incidencias / ventas)
//   GET  consumos_incidencia&incidencia_id=
//   POST guardar              alta o edición de artículo
//   POST estado               activar / desactivar artículo
//   POST movimiento           entrada, salida o ajuste manual
//   POST consumir_incidencia  refacción usada en una incidencia (salida)
//   POST devolver_incidencia  regresa al almacén lo consumido en una incidencia
//   POST aplicar_servicio     servicio o mano de obra aplicado en una orden (D-23, sin existencias)
//   POST quitar_servicio      JSON: incidencia_id, id
//   POST solicitar_articulo   el Técnico pide el alta de una refacción: queda como nota en la orden (O-7)
//   POST subir_imagen&id=     foto del producto (multipart "imagen", JPG o PNG)
//   POST quitar_imagen        JSON: id
//
// Los POST reciben JSON. Permisos: módulo "inventario" para administrar; las
// acciones de incidencia exigen el módulo "incidencias" (el técnico registra
// refacciones sin necesitar acceso al almacén).
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../auth/permisos.php';
require_once __DIR__ . '/../auth/audit.php';
require_once __DIR__ . '/lib/inventario_helpers.php';
require_once __DIR__ . '/lib/incidencias_helpers.php';

header('Content-Type: application/json');

function responder(array $datos, int $codigo = 200): void {
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

function exigirAlgunPermiso(string ...$modulos): void {
    foreach ($modulos as $m) {
        if (tienePermiso($m)) return;
    }
    responder(['exito' => false, 'mensaje' => 'Acceso denegado'], 403);
}

function articuloPorId(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare("SELECT * FROM inventario_articulos WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// Bloquea la orden (FOR UPDATE) hasta el fin de la transacción: dos devoluciones o
// consumos simultáneos de la misma orden se hacen uno tras otro, y el segundo ve lo
// que registró el primero (antes podían devolver dos veces lo consumido, C-H01).
// También frena un consumo mientras la orden se elimina (C-H08).
function folioIncidencia(PDO $pdo, int $id): ?string {
    $stmt = $pdo->prepare("SELECT numero_incidente, tecnico FROM incidencias WHERE id = ? FOR UPDATE");
    $stmt->execute([$id]);
    $orden = $stmt->fetch(PDO::FETCH_ASSOC);
    $folio = $orden ? $orden['numero_incidente'] : false;
    // D-9: para el Técnico, una orden ajena es como si no existiera
    if ($orden && !ordenVisibleParaSesion($pdo, $orden['tecnico'] ?? '')) $folio = false;
    if ($folio === false) {
        throw new InventarioException('La orden de servicio no existe.');
    }
    return $folio ?: null;
}

$accion = $_GET['accion'] ?? '';
$metodo = $_SERVER['REQUEST_METHOD'];
$body = $metodo === 'POST' && empty($_FILES) ? (json_decode(file_get_contents('php://input'), true) ?: []) : [];

if (!invTablasDisponibles($pdo)) {
    responder(['exito' => false, 'mensaje' => 'El módulo de inventario no está instalado en la base de datos (ver database/schema.sql).'], 500);
}

try {
    switch ("$metodo:$accion") {

    case 'GET:listar':
        exigirAlgunPermiso('inventario');
        $where = [];
        $params = [];
        if (empty($_GET['inactivos'])) $where[] = 'activo = 1';
        if (!empty($_GET['categoria'])) { $where[] = 'categoria = ?'; $params[] = $_GET['categoria']; }
        if (!empty($_GET['bajo_minimo'])) $where[] = 'existencia <= stock_minimo' . invSoloFisicos($pdo);
        if (($_GET['tipo'] ?? '') === 'productos') $where[] = '1 = 1' . invSoloFisicos($pdo);
        if (($_GET['tipo'] ?? '') === 'servicios' && invTieneControlExistencia($pdo)) $where[] = 'controla_existencia = 0';
        if (trim($_GET['buscar'] ?? '') !== '') {
            $where[] = '(sku LIKE ? OR nombre LIKE ? OR marca LIKE ? OR modelo LIKE ? OR ubicacion LIKE ?)';
            $like = '%' . trim($_GET['buscar']) . '%';
            array_push($params, $like, $like, $like, $like, $like);
        }
        $sql = "SELECT * FROM inventario_articulos" . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . " ORDER BY categoria, nombre";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        responder(['exito' => true, 'articulos' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);

    case 'GET:resumen':
        exigirAlgunPermiso('inventario');
        // Valuación a costo: por defecto en MXN (los artículos en USD se convierten
        // con el tipo de cambio de la empresa) y, aparte, el valor nativo en USD.
        require_once __DIR__ . '/lib/cotizaciones_helpers.php';
        $invTieneMoneda = invTieneMoneda($pdo);
        $invTc = cotTipoCambioEmpresa();
        $valorMXN = invSqlValorMXN($invTc, $invTieneMoneda);
        $valorUSD = invSqlValorUSDNativo($invTieneMoneda);
        $hayUsd = $invTieneMoneda ? "COALESCE(SUM(moneda = 'USD'), 0)" : "0";
        $kpi = $pdo->query(
            "SELECT COUNT(*) AS articulos,
                    $valorMXN AS valor,
                    $valorUSD AS valor_usd,
                    $hayUsd AS hay_usd,
                    SUM(existencia <= stock_minimo) AS bajo_minimo,
                    SUM(existencia <= 0) AS agotados
             FROM inventario_articulos WHERE activo = 1" . invSoloFisicos($pdo)
        )->fetch(PDO::FETCH_ASSOC);
        $kpi['tipo_cambio'] = $invTc;
        if (invTieneControlExistencia($pdo)) {
            $kpi['servicios'] = (int)$pdo->query("SELECT COUNT(*) FROM inventario_articulos WHERE activo = 1 AND controla_existencia = 0")->fetchColumn();
        }

        $kpi['movimientos_30d'] = (int)$pdo->query(
            "SELECT COUNT(*) FROM inventario_movimientos WHERE creado_en >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
        )->fetchColumn();

        // Salidas de los últimos 6 meses separadas por origen (valor a costo)
        $consumo = $pdo->query(
            "SELECT DATE_FORMAT(creado_en, '%Y-%m') AS mes,
                    COALESCE(referencia_tipo, 'manual') AS origen,
                    SUM(-cantidad * COALESCE(costo_unitario, 0)) AS valor,
                    SUM(-cantidad) AS unidades
             FROM inventario_movimientos
             WHERE cantidad < 0 AND creado_en >= DATE_SUB(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 5 MONTH)
             GROUP BY mes, origen ORDER BY mes"
        )->fetchAll(PDO::FETCH_ASSOC);

        $top = $pdo->query(
            "SELECT a.id, a.sku, a.nombre, a.unidad, SUM(-m.cantidad) AS unidades
             FROM inventario_movimientos m JOIN inventario_articulos a ON a.id = m.articulo_id
             WHERE m.cantidad < 0 AND m.referencia_tipo IN ('incidencia', 'venta')
               AND m.creado_en >= DATE_SUB(NOW(), INTERVAL 180 DAY)
             GROUP BY a.id, a.sku, a.nombre, a.unidad
             ORDER BY unidades DESC LIMIT 5"
        )->fetchAll(PDO::FETCH_ASSOC);

        responder(['exito' => true, 'kpi' => $kpi, 'consumo_mensual' => $consumo, 'mas_usados' => $top]);

    case 'GET:kardex':
        exigirAlgunPermiso('inventario');
        $id = (int)($_GET['id'] ?? 0);
        $art = articuloPorId($pdo, $id);
        if (!$art) responder(['exito' => false, 'mensaje' => 'Artículo no encontrado'], 404);
        $stmt = $pdo->prepare("SELECT * FROM inventario_movimientos WHERE articulo_id = ? ORDER BY creado_en DESC, id DESC LIMIT 300");
        $stmt->execute([$id]);
        responder(['exito' => true, 'articulo' => $art, 'movimientos' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);

    case 'GET:opciones':
        exigirAlgunPermiso('inventario', 'incidencias', 'ventas');
        // Refacciones de incidencias y artículo de ventas: solo productos físicos
        // D-23: el detalle de la orden pide también los servicios (con_servicios=1)
        $where = 'activo = 1' . (!empty($_GET['con_servicios']) ? '' : invSoloFisicos($pdo));
        $params = [];
        if (!empty($_GET['categoria'])) { $where .= ' AND categoria = ?'; $params[] = $_GET['categoria']; }
        $stmt = $pdo->prepare("SELECT id, sku, nombre, categoria, marca, modelo, unidad, existencia, precio_venta" . (invTieneSeries($pdo) ? ', controla_serie' : '') . "
                               FROM inventario_articulos WHERE $where ORDER BY nombre");
        $stmt->execute($params);
        responder(['exito' => true, 'articulos' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);

    case 'GET:consumos_incidencia':
        exigirAlgunPermiso('incidencias');
        $incId = (int)($_GET['incidencia_id'] ?? 0);
        if (!ordenVisiblePorId($pdo, $incId)) responder(['exito' => false, 'mensaje' => 'La orden de servicio no existe.'], 404);
        // D-51: el importe es al precio con que se aplicó cada pieza
        $precioMov = invTienePrecioCongelado($pdo) ? 'COALESCE(m.precio_unitario, a.precio_venta)' : 'a.precio_venta';
        $stmt = $pdo->prepare(
            "SELECT a.id AS articulo_id, a.sku, a.nombre, a.unidad, a.precio_venta,
                    SUM(-m.cantidad) AS cantidad, SUM(-m.cantidad * $precioMov) AS importe, MAX(m.creado_en) AS ultimo
             FROM inventario_movimientos m JOIN inventario_articulos a ON a.id = m.articulo_id
             WHERE m.referencia_tipo = 'incidencia' AND m.referencia_id = ?
             GROUP BY a.id, a.sku, a.nombre, a.unidad, a.precio_venta
             HAVING cantidad > 0
             ORDER BY ultimo DESC"
        );
        $stmt->execute([$incId]);
        responder(['exito' => true, 'consumos' => $stmt->fetchAll(PDO::FETCH_ASSOC),
            'servicios' => invServiciosDeIncidencia($pdo, $incId), 'reemplazos' => invReemplazosDeIncidencia($pdo, $incId),
            'conceptos_disponibles' => invServiciosDisponibles($pdo)]);

    case 'POST:guardar':
        exigirAlgunPermiso('inventario');
        $id = (int)($body['id'] ?? 0);
        $campos = [
            'sku'            => strtoupper(trim((string)($body['sku'] ?? ''))),
            'nombre'         => trim((string)($body['nombre'] ?? '')),
            'categoria'      => trim((string)($body['categoria'] ?? '')),
            'marca'          => trim((string)($body['marca'] ?? '')) ?: null,
            'modelo'         => trim((string)($body['modelo'] ?? '')) ?: null,
            'descripcion'    => trim((string)($body['descripcion'] ?? '')) ?: null,
            'unidad'         => trim((string)($body['unidad'] ?? '')) ?: 'pza',
            'stock_minimo'   => max(0, invNumero($body['stock_minimo'] ?? 0)),
            'costo_unitario' => max(0, invNumero($body['costo_unitario'] ?? 0)),
            'precio_venta'   => max(0, invNumero($body['precio_venta'] ?? 0)),
            'ubicacion'      => trim((string)($body['ubicacion'] ?? '')) ?: null,
        ];
        if ($campos['sku'] === '' || $campos['nombre'] === '') {
            throw new InventarioException('SKU y nombre son obligatorios.');
        }
        if (!in_array($campos['categoria'], INV_CATEGORIAS, true)) {
            throw new InventarioException('Categoría no válida.');
        }
        $fisico = invCategoriaControlaExistencia($campos['categoria']);
        if (invTieneControlExistencia($pdo)) {
            $campos['controla_existencia'] = $fisico ? 1 : 0;
        }
        if (invTieneSeries($pdo)) {  // D-45: solo piezas físicas
            $campos['controla_serie'] = $fisico && !empty($body['controla_serie']) && $body['controla_serie'] !== '0' ? 1 : 0;
        }
        if (invTieneMoneda($pdo)) {
            $moneda = strtoupper(trim((string)($body['moneda'] ?? 'MXN')));
            $campos['moneda'] = in_array($moneda, ['MXN', 'USD'], true) ? $moneda : 'MXN';
        }
        if (invTieneTasaIva($pdo)) {
            $tasaIva = (float)($body['tasa_iva'] ?? 0.16);
            if (!in_array($tasaIva, [0.0, 0.08, 0.16], true)) throw new InventarioException('Tasa de IVA no válida. Usa 0.00, 0.08 o 0.16.');
            $campos['tasa_iva'] = $tasaIva;
        }
        if (!$fisico) {
            // Un servicio no tiene mínimo ni ubicación
            $campos['stock_minimo'] = 0;
            $campos['ubicacion'] = null;
        }
        $dup = $pdo->prepare("SELECT id FROM inventario_articulos WHERE sku = ? AND id <> ?");
        $dup->execute([$campos['sku'], $id]);
        if ($dup->fetchColumn()) {
            throw new InventarioException("Ya existe un artículo con el SKU {$campos['sku']}.");
        }

        $pdo->beginTransaction();
        if ($id) {
            $antes = articuloPorId($pdo, $id);
            if (!$antes) throw new InventarioException('Artículo no encontrado.');
            if (!$fisico && (float)$antes['existencia'] != 0) {
                throw new InventarioException('Este artículo tiene existencias: no puede pasar a Servicio o Mano de obra. Haz primero un ajuste a cero.');
            }
            $sets = implode(', ', array_map(fn($c) => "$c = ?", array_keys($campos)));
            $pdo->prepare("UPDATE inventario_articulos SET $sets, actualizado_en = NOW() WHERE id = ?")
                ->execute([...array_values($campos), $id]);
            $pdo->commit();
            registrarAuditoria('inventario_articulos', $id, 'UPDATE', array_intersect_key($antes, $campos), $campos, $campos['sku']);
        } else {
            $cols = implode(', ', array_keys($campos));
            $marcas = implode(', ', array_fill(0, count($campos), '?'));
            $pdo->prepare("INSERT INTO inventario_articulos ($cols) VALUES ($marcas)")->execute(array_values($campos));
            $id = (int)$pdo->lastInsertId();
            $inicial = $fisico ? invNumero($body['existencia_inicial'] ?? 0) : 0;
            // La creación va al historial antes que la entrada de su existencia inicial
            registrarAuditoria('inventario_articulos', $id, 'CREATE', null, $campos + ['existencia_inicial' => $inicial], $campos['sku']);
            if ($inicial > 0) {
                invRegistrarMovimiento($pdo, $id, $inicial, 'entrada', 'Existencia inicial', 'manual');
            }
            $pdo->commit();
        }
        responder(['exito' => true, 'id' => $id, 'articulo' => articuloPorId($pdo, $id)]);

    case 'POST:subir_imagen':
    case 'POST:quitar_imagen':
        exigirAlgunPermiso('inventario');
        if (!invTieneImagen($pdo)) throw new InventarioException('Para guardar fotos de productos aplica la migración 008.');
        $id = (int)($accion === 'subir_imagen' ? ($_GET['id'] ?? 0) : ($body['id'] ?? 0));
        $antes = articuloPorId($pdo, $id);
        if (!$antes) throw new InventarioException('Artículo no encontrado.');
        $nueva = $accion === 'subir_imagen' ? invGuardarImagen($id, $_FILES['imagen'] ?? []) : null;
        $pdo->prepare('UPDATE inventario_articulos SET imagen = ?, actualizado_en = NOW() WHERE id = ?')->execute([$nueva, $id]);
        invBorrarImagen($antes['imagen'] ?? null);
        registrarAuditoria('inventario_articulos', $id, 'UPDATE', ['imagen' => $antes['imagen'] ?? null], ['imagen' => $nueva], $antes['sku']);
        responder(['exito' => true, 'imagen' => $nueva]);

    case 'POST:estado':
        exigirAlgunPermiso('inventario');
        $id = (int)($body['id'] ?? 0);
        $activo = !empty($body['activo']) ? 1 : 0;
        $antes = articuloPorId($pdo, $id);
        if (!$antes) throw new InventarioException('Artículo no encontrado.');
        $pdo->prepare("UPDATE inventario_articulos SET activo = ?, actualizado_en = NOW() WHERE id = ?")->execute([$activo, $id]);
        registrarAuditoria('inventario_articulos', $id, 'UPDATE', ['activo' => (int)$antes['activo']], ['activo' => $activo], $antes['sku']);
        responder(['exito' => true]);

    case 'POST:movimiento':
        exigirAlgunPermiso('inventario');
        $id = (int)($body['articulo_id'] ?? 0);
        $tipo = $body['tipo'] ?? '';
        $cantidad = invNumero($body['cantidad'] ?? 0);
        $motivo = trim((string)($body['motivo'] ?? '')) ?: null;

        $pdo->beginTransaction();
        if ($tipo === 'ajuste') {
            // En un ajuste la cantidad es el conteo físico: se registra la diferencia
            if ($cantidad < 0) throw new InventarioException('El conteo físico no puede ser negativo.');
            // C-H07: la diferencia se calcula con el artículo bloqueado (un consumo simultáneo espera)
            $st = $pdo->prepare('SELECT * FROM inventario_articulos WHERE id = ? FOR UPDATE');
            $st->execute([$id]);
            $art = $st->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$art) throw new InventarioException('Artículo no encontrado.');
            $delta = round($cantidad - (float)$art['existencia'], 2);
            if (abs($delta) < 0.005) throw new InventarioException('El conteo coincide con la existencia actual: no hay nada que ajustar.');
            $nueva = invRegistrarMovimiento($pdo, $id, $delta, 'ajuste', $motivo ?: 'Ajuste por conteo físico', 'manual');
        } else {
            if ($cantidad <= 0) throw new InventarioException('La cantidad debe ser mayor a cero.');
            if (!in_array($tipo, ['entrada', 'salida'], true)) throw new InventarioException('Tipo de movimiento no válido.');
            $nueva = invRegistrarMovimiento($pdo, $id, $tipo === 'entrada' ? $cantidad : -$cantidad, $tipo, $motivo, 'manual');
        }
        $pdo->commit();
        responder(['exito' => true, 'existencia' => $nueva]);

    case 'POST:consumir_incidencia':
        exigirAlgunPermiso('incidencias');
        $incId = (int)($body['incidencia_id'] ?? 0);
        $artId = (int)($body['articulo_id'] ?? 0);
        $cantidad = invNumero($body['cantidad'] ?? 0);
        if ($cantidad <= 0) throw new InventarioException('La cantidad debe ser mayor a cero.');
        // L-30: un artículo desactivado no se aplica (la interfaz ya no lo ofrece; aquí se hace cumplir)
        $stAct = $pdo->prepare('SELECT activo, nombre FROM inventario_articulos WHERE id = ?');
        $stAct->execute([$artId]);
        $artAct = $stAct->fetch(PDO::FETCH_ASSOC);
        if ($artAct && !(int)$artAct['activo']) throw new InventarioException($artAct['nombre'] . ' está desactivado en el catálogo: actívalo para aplicarlo.');
        $esReemplazo = !empty($body['reemplazo']) && is_array($body['reemplazo']);
        $series = $esReemplazo ? [] : invSeriesExigidas($pdo, $artId, $cantidad, $body['series'] ?? null);  // D-45
        $pdo->beginTransaction();
        $folio = folioIncidencia($pdo, $incId);
        $nueva = invRegistrarMovimiento($pdo, $artId, -$cantidad, 'salida',
            ($esReemplazo ? 'Equipo de reemplazo instalado en la orden de servicio ' : 'Utilizado en orden de servicio ') . ($folio ?: "#$incId")
            . ($series ? ' (N/S ' . implode(', ', $series) . ')' : ''), 'incidencia', $incId, $folio);
        if ($series) {  // D-45: las series quedan en el movimiento
            $st = $pdo->prepare("SELECT MAX(id) FROM inventario_movimientos WHERE articulo_id = ? AND referencia_tipo = 'incidencia' AND referencia_id = ?");
            $st->execute([$artId, $incId]);
            $pdo->prepare('UPDATE inventario_movimientos SET numero_serie = ? WHERE id = ?')->execute([implode(', ', $series), (int)$st->fetchColumn()]);
        }
        // D-23: el equipo de reemplazo queda en el padrón del cliente, ligado a la orden
        if ($esReemplazo) {
            $padron = invRegistrarReemplazo($pdo, $incId, $artId, $cantidad, $body['reemplazo']);
            registrarAuditoria('padron_equipos', $padron, 'CREATE', null, ['numero_serie' => trim((string)$body['reemplazo']['numero_serie']),
                'origen' => 'Reemplazo', 'incidencia' => $folio, 'articulo_id' => $artId], $folio);
        }
        // H-09 · D-47: lo que se aplicó desde la orden (p. ej. con su serie, L-14) queda como registrado en la
        // cotización aprobada de la orden, para que «Cargar refacciones» no lo ofrezca otra vez
        if ($pdo->query("SHOW TABLES LIKE 'cotizacion_partidas'")->fetch()) {
            require_once __DIR__ . '/lib/cotizaciones_helpers.php';
            cotConciliarAplicado($pdo, $incId, $artId, $folio);  // L2-6 · L2-7 · H-03
        }
        $pdo->commit();
        responder(['exito' => true, 'existencia' => $nueva]);

    case 'POST:devolver_incidencia':
        exigirAlgunPermiso('incidencias');
        $incId = (int)($body['incidencia_id'] ?? 0);
        $artId = (int)($body['articulo_id'] ?? 0);
        $pdo->beginTransaction();
        $folio = folioIncidencia($pdo, $incId);
        $consumido = invConsumoPorReferencia($pdo, 'incidencia', $incId)[$artId] ?? 0;
        $cantidad = isset($body['cantidad']) ? invNumero($body['cantidad']) : $consumido;
        // D-23: un equipo de reemplazo se devuelve hasta que se quita del padrón del cliente
        $enPadron = invReemplazosDeIncidencia($pdo, $incId, $artId);
        if ($enPadron && $consumido - $cantidad < count($enPadron) - 0.0001) {
            throw new InventarioException('Este equipo se dio de alta en el padrón del cliente (serie ' . $enPadron[0]['numero_serie']
                . '). Quítalo del padrón antes de devolverlo al almacén.');
        }
        if ($cantidad <= 0 || $cantidad > $consumido) {
            throw new InventarioException('Solo se puede devolver lo que se registró en esta orden de servicio.');
        }
        $nueva = invRegistrarMovimiento($pdo, $artId, $cantidad, 'entrada',
            'Devuelto de la orden de servicio ' . ($folio ?: "#$incId"), 'incidencia', $incId, $folio);
        // Si ya no queda nada de ese artículo en la orden, las cotizaciones ligadas dejan de
        // marcarlo como instalado y se puede volver a cargar desde la cotización
        // H-02 (fase 4): la conciliación desmarca lo que la devolución deja sin cubrir (también parcial)
        if ($pdo->query("SHOW TABLES LIKE 'cotizacion_partidas'")->fetch()) {
            require_once __DIR__ . '/lib/cotizaciones_helpers.php';
            cotConciliarAplicado($pdo, $incId, $artId, $folio);
        }
        if (($consumido - $cantidad) <= 0.0001 && $pdo->query("SHOW TABLES LIKE 'cotizacion_partidas'")->fetch()) {
            $pdo->prepare("UPDATE cotizacion_partidas p JOIN cotizaciones c ON c.id = p.cotizacion_id
                           SET p.cargada_incidencia = 0
                           WHERE c.incidencia_id = ? AND p.articulo_id = ? AND p.tipo IN ('refaccion', 'equipo') AND p.cargada_incidencia = 1")  // H-09
                ->execute([$incId, $artId]);
        }
        $pdo->commit();
        responder(['exito' => true, 'existencia' => $nueva]);

    case 'POST:aplicar_servicio':
        exigirAlgunPermiso('incidencias');
        $incId = (int)($body['incidencia_id'] ?? 0);
        $pdo->beginTransaction();
        $folio = folioIncidencia($pdo, $incId);  // bloquea la orden y aplica D-9
        $servicio = invAplicarServicio($pdo, $incId, (int)($body['articulo_id'] ?? 0), invNumero($body['cantidad'] ?? 1),
            (int)($_SESSION['user_id'] ?? 0) ?: null, $_SESSION['nombre'] ?? null);
        registrarAuditoria('incidencias', $incId, 'CONCEPTO', null, ['servicio_aplicado' => (int)($body['articulo_id'] ?? 0), 'cantidad' => invNumero($body['cantidad'] ?? 1)], $folio);
        $pdo->commit();
        responder(['exito' => true, 'id' => $servicio]);

    case 'POST:quitar_servicio':
        exigirAlgunPermiso('incidencias');
        $incId = (int)($body['incidencia_id'] ?? 0);
        invExigirServicios($pdo);
        $pdo->beginTransaction();
        $folio = folioIncidencia($pdo, $incId);
        $st = $pdo->prepare('SELECT * FROM incidencia_servicios WHERE id = ? AND incidencia_id = ? FOR UPDATE');
        $st->execute([(int)($body['id'] ?? 0), $incId]);
        $fila = $st->fetch(PDO::FETCH_ASSOC);
        if (!$fila) throw new InventarioException('El servicio ya no está en esta orden de servicio.');
        $pdo->prepare('DELETE FROM incidencia_servicios WHERE id = ?')->execute([$fila['id']]);
        registrarAuditoria('incidencias', $incId, 'CONCEPTO', ['servicio' => $fila['descripcion'], 'cantidad' => $fila['cantidad']], ['servicio_quitado' => $fila['descripcion']], $folio);
        $pdo->commit();
        responder(['exito' => true]);

    case 'POST:solicitar_articulo':
        // O-7: quien no tiene Inventario pide el alta; queda como nota en la orden
        exigirAlgunPermiso('incidencias');
        $incId = (int)($body['incidencia_id'] ?? 0);
        $nombre = trim((string)($body['nombre'] ?? ''));
        if ($nombre === '') throw new InventarioException('Escribe qué refacción o artículo hay que dar de alta.');
        $pdo->beginTransaction();
        $folio = folioIncidencia($pdo, $incId);
        $st = $pdo->prepare('SELECT notas FROM incidencias WHERE id = ?');
        $st->execute([$incId]);
        $antes = (string)$st->fetchColumn();
        $linea = '[Solicitud de alta en inventario] ' . mb_substr($nombre, 0, 150)
            . (trim((string)($body['notas'] ?? '')) !== '' ? ' (' . mb_substr(trim((string)$body['notas']), 0, 200) . ')' : '')
            . ' · ' . ($_SESSION['nombre'] ?? 'Usuario') . ', ' . date('d/m/Y H:i');
        $notas = trim($antes) === '' ? $linea : rtrim($antes) . "\n" . $linea;
        $pdo->prepare('UPDATE incidencias SET notas = ? WHERE id = ?')->execute([$notas, $incId]);
        registrarAuditoria('incidencias', $incId, 'UPDATE', ['notas' => $antes], ['notas' => $notas, 'solicitud_articulo' => $nombre], $folio);
        $st = $pdo->prepare('SELECT * FROM incidencias WHERE id = ?');
        $st->execute([$incId]);
        $version = versionIncidencia($st->fetch(PDO::FETCH_ASSOC) ?: []);
        $pdo->commit();
        // La versión nueva deja que el detalle siga editando sin un conflicto falso (D-25)
        responder(['exito' => true, 'notas' => $notas, 'version' => $version]);

    default:
        responder(['exito' => false, 'mensaje' => 'Acción no válida'], 400);
    }
} catch (InventarioException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    responder(['exito' => false, 'mensaje' => mensajeErrorPublico($e)], 422);
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Inventario: ' . $e->getMessage());
    responder(['exito' => false, 'mensaje' => 'Error interno del inventario.'], 500);
}
