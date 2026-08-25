<?php
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../auth/audit.php';
header('Content-Type: application/json');

$idVenta = $_POST['venta_id'] ?? null;

if (!$idVenta) {
    echo json_encode(['exito' => false, 'mensaje' => 'ID de venta inválido o no recibido']);
    exit;
}

// Captura una foto completa de la venta (cabecera + equipos) para el historial.
function fotografiarVenta(PDO $pdo, int $ventaId): array {
    $stmtCab = $pdo->prepare("SELECT folio, cliente, sucursal, fecha_registro FROM ventas WHERE id = ?");
    $stmtCab->execute([$ventaId]);
    $cabecera = $stmtCab->fetch(PDO::FETCH_ASSOC) ?: [];

    $stmtDet = $pdo->prepare(
        "SELECT id, equipo, marca, modelo, numero_serie, garantia, calibracion, servicio, frecuencia_servicio, notas
         FROM venta_detalles WHERE venta_id = ? ORDER BY id"
    );
    $stmtDet->execute([$ventaId]);
    $cabecera['equipos'] = $stmtDet->fetchAll(PDO::FETCH_ASSOC);

    return $cabecera;
}

// Mantiene sincronizado el Padron de Equipos (fuente real de "Programadas" y de los
// tickets automaticos) con lo que se edita en la venta. La venta es el origen: el
// padron solo refleja lo que aqui se decide, nunca al reves.
function sincronizarPadronEquipo(
    PDO $pdo,
    int $ventaId,
    int $ventaDetalleId,
    ?string $numeroSerieAnterior,
    string $numeroSerieNueva,
    string $cliente,
    string $sucursal,
    string $equipo,
    string $marca,
    string $modelo,
    int $garantia,
    int $calibracion,
    int $tieneServicio,
    int $frecuenciaServicio,
    string $fechaBase
): void {
    $proximaCalibracion = $calibracion > 0 ? date('Y-m-d', strtotime("$fechaBase +$calibracion months")) : null;
    $proximoServicio = ($tieneServicio && $frecuenciaServicio > 0) ? date('Y-m-d', strtotime("$fechaBase +$frecuenciaServicio months")) : null;

    // Emparejamos primero por venta_detalle_id (exacto, sin ambigüedad). Si el registro
    // del padrón es anterior a este enlace (todavía no tiene venta_detalle_id), caemos
    // a buscar por numero_serie como respaldo.
    $stmtBuscarPorDetalle = $pdo->prepare("SELECT id FROM padron_equipos WHERE venta_detalle_id = ? LIMIT 1");
    $stmtBuscarPorDetalle->execute([$ventaDetalleId]);
    $idPadron = $stmtBuscarPorDetalle->fetchColumn();

    if (!$idPadron) {
        $claveBusqueda = $numeroSerieAnterior ?: $numeroSerieNueva;
        $stmtBuscarPorSerie = $pdo->prepare("SELECT id FROM padron_equipos WHERE venta_id = ? AND numero_serie = ? AND venta_detalle_id IS NULL LIMIT 1");
        $stmtBuscarPorSerie->execute([$ventaId, $claveBusqueda]);
        $idPadron = $stmtBuscarPorSerie->fetchColumn();
    }

    if ($idPadron) {
        $stmtActualizar = $pdo->prepare(
            "UPDATE padron_equipos SET
                venta_detalle_id = ?, cliente = ?, sucursal = ?, equipo = ?, marca = ?, modelo = ?, numero_serie = ?,
                calibracion = ?, servicio = ?, frecuencia_servicio = ?, garantia = ?,
                proxima_calibracion = ?, proximo_servicio = ?
             WHERE id = ?"
        );
        $stmtActualizar->execute([
            $ventaDetalleId, $cliente, $sucursal, $equipo, $marca, $modelo, $numeroSerieNueva,
            $calibracion, $tieneServicio, $frecuenciaServicio, $garantia,
            $proximaCalibracion, $proximoServicio, $idPadron
        ]);
    } else {
        // No existia en el padron (caso raro/defensivo): lo creamos para no perder trazabilidad
        $stmtCrear = $pdo->prepare(
            "INSERT INTO padron_equipos
                (cliente, sucursal, equipo, marca, modelo, numero_serie, calibracion, servicio, frecuencia_servicio, garantia, proxima_calibracion, proximo_servicio, origen, venta_id, venta_detalle_id, fecha_registro)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Venta SIPCONS', ?, ?, ?)"
        );
        $stmtCrear->execute([
            $cliente, $sucursal, $equipo, $marca, $modelo, $numeroSerieNueva,
            $calibracion, $tieneServicio, $frecuenciaServicio, $garantia,
            $proximaCalibracion, $proximoServicio, $ventaId, $ventaDetalleId, $fechaBase
        ]);
    }
}

try {
    $ventaAntes = fotografiarVenta($pdo, (int)$idVenta);

    $pdo->beginTransaction();

    // ==========================================
    // 1. ACTUALIZAR CABECERA (Tabla: ventas)
    // ==========================================

    // Capturamos el valor del input "fecha_venta" del HTML
    $fechaVenta = !empty($_POST['fecha_venta']) ? $_POST['fecha_venta'] : null;

    // COALESCE: si el formulario llega sin fecha, conservamos la que ya tenia la venta
    // en vez de dejarla en NULL (y de paso mantenemos una base de fecha valida para
    // recalcular las proximas calibraciones/servicios del padron).
    $stmtV = $pdo->prepare("UPDATE ventas SET
        cliente = ?,
        sucursal = ?,
        fecha_registro = COALESCE(?, fecha_registro),
        fecha_actualizacion = NOW()
        WHERE id = ?");

    $stmtV->execute([
        $_POST['cliente'] ?? '',
        $_POST['sucursal'] ?? '',
        $fechaVenta,
        $idVenta
    ]);

    // Valores finales ya confirmados en BD, para usar como base en la sincronizacion del padron
    $stmtVentaActual = $pdo->prepare("SELECT cliente, sucursal, fecha_registro FROM ventas WHERE id = ?");
    $stmtVentaActual->execute([$idVenta]);
    $ventaActual = $stmtVentaActual->fetch(PDO::FETCH_ASSOC);
    $clienteFinal = $ventaActual['cliente'];
    $sucursalFinal = $ventaActual['sucursal'];
    $fechaBaseFinal = $ventaActual['fecha_registro'];

    // ==========================================
    // 2. ACTUALIZAR, INSERTAR O ELIMINAR SERIES DINÁMICAMENTE
    // ==========================================
    if (isset($_POST['series_json'])) {
        $seriesRecibidas = json_decode($_POST['series_json'], true);
        
        // IDs actuales en la base de datos para esta venta
        $stmtCurrent = $pdo->prepare("SELECT id FROM venta_detalles WHERE venta_id = ?");
        $stmtCurrent->execute([$idVenta]);
        $idsActuales = $stmtCurrent->fetchAll(PDO::FETCH_COLUMN);

        $idsQueSeQuedan = [];

        // Evaluar variables de servicio
        $tieneServicio = !empty($_POST['servicio']) ? 1 : 0;
        $frecuencia = $tieneServicio ? ($_POST['frecuencia_servicio'] ?? 0) : 0;

        // Preparar sentencias SQL
        $stmtUpdate = $pdo->prepare("UPDATE venta_detalles SET equipo=?, marca=?, modelo=?, numero_serie=?, garantia=?, calibracion=?, servicio=?, frecuencia_servicio=?, notas=? WHERE id=?");
        $stmtInsert = $pdo->prepare("INSERT INTO venta_detalles (venta_id, equipo, marca, modelo, numero_serie, garantia, calibracion, servicio, frecuencia_servicio, notas) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        if (!empty($seriesRecibidas)) {
            $garantiaInt = (int)($_POST['garantia'] ?? 0);
            $calibracionInt = (int)($_POST['calibracion'] ?? 0);
            $frecuenciaInt = (int)$frecuencia;
            $serieNueva = null;

            foreach ($seriesRecibidas as $s) {
                $serieNueva = trim($s['serie']);

                if (!empty($s['id_detalle']) && $s['id_detalle'] !== 'nuevo') {
                    // Capturamos la serie ANTERIOR (antes de sobreescribirla) para poder
                    // ubicar el equipo correspondiente en el Padrón de Equipos.
                    $stmtSerieAnterior = $pdo->prepare("SELECT numero_serie FROM venta_detalles WHERE id = ?");
                    $stmtSerieAnterior->execute([$s['id_detalle']]);
                    $serieAnterior = $stmtSerieAnterior->fetchColumn() ?: null;

                    // ACTUALIZAR serie existente
                    $idsQueSeQuedan[] = $s['id_detalle'];
                    $stmtUpdate->execute([
                        $_POST['equipo'], $_POST['marca'], $_POST['modelo'],
                        $serieNueva,
                        $garantiaInt, $calibracionInt,
                        $tieneServicio, $frecuenciaInt, $_POST['notas'] ?? '',
                        $s['id_detalle']
                    ]);

                    sincronizarPadronEquipo(
                        $pdo, (int)$idVenta, (int)$s['id_detalle'], $serieAnterior, $serieNueva,
                        $clienteFinal, $sucursalFinal, $_POST['equipo'], $_POST['marca'], $_POST['modelo'],
                        $garantiaInt, $calibracionInt, $tieneServicio, $frecuenciaInt, $fechaBaseFinal
                    );
                } else {
                    // INSERTAR serie nueva (si la cantidad de equipos aumentó)
                    $stmtInsert->execute([
                        $idVenta,
                        $_POST['equipo'], $_POST['marca'], $_POST['modelo'],
                        $serieNueva,
                        $garantiaInt, $calibracionInt,
                        $tieneServicio, $frecuenciaInt, $_POST['notas'] ?? ''
                    ]);
                    $nuevoDetalleId = (int)$pdo->lastInsertId();

                    sincronizarPadronEquipo(
                        $pdo, (int)$idVenta, $nuevoDetalleId, null, $serieNueva,
                        $clienteFinal, $sucursalFinal, $_POST['equipo'], $_POST['marca'], $_POST['modelo'],
                        $garantiaInt, $calibracionInt, $tieneServicio, $frecuenciaInt, $fechaBaseFinal
                    );
                }
            }
        }

        // Eliminar las series que se quitaron en pantalla al reducir la cantidad
        $idsAEliminar = array_values(array_diff($idsActuales, $idsQueSeQuedan));
        if (!empty($idsAEliminar)) {
            // Parámetros nombrados (más robustos que "?" posicionales para listas
            // IN(...) de tamaño variable): cada id recibe su propia clave :id_del_N.
            $paramsIds = [];
            $placeholdersNombrados = [];
            foreach ($idsAEliminar as $i => $idDetalle) {
                $clave = ":id_del_$i";
                $placeholdersNombrados[] = $clave;
                $paramsIds[$clave] = $idDetalle;
            }
            $listaPlaceholders = implode(',', $placeholdersNombrados);

            // Antes de borrar el detalle, quitamos del Padrón el equipo correspondiente:
            // si ya no forma parte de la venta, tampoco debe seguir programado.
            // 1) Emparejamiento exacto por venta_detalle_id.
            $stmtDeletePadronPorDetalle = $pdo->prepare("DELETE FROM padron_equipos WHERE venta_detalle_id IN ($listaPlaceholders)");
            $stmtDeletePadronPorDetalle->execute($paramsIds);

            // 2) Respaldo por numero_serie, solo para filas antiguas sin el enlace.
            $stmtSeriesAEliminar = $pdo->prepare("SELECT numero_serie FROM venta_detalles WHERE id IN ($listaPlaceholders)");
            $stmtSeriesAEliminar->execute($paramsIds);
            $seriesAEliminar = array_values(array_unique(array_filter($stmtSeriesAEliminar->fetchAll(PDO::FETCH_COLUMN))));

            if (!empty($seriesAEliminar)) {
                $paramsSeries = [':venta_id_del' => $idVenta];
                $placeholdersSeriesNombrados = [];
                foreach ($seriesAEliminar as $i => $serie) {
                    $clave = ":serie_del_$i";
                    $placeholdersSeriesNombrados[] = $clave;
                    $paramsSeries[$clave] = $serie;
                }
                $listaPlaceholdersSeries = implode(',', $placeholdersSeriesNombrados);
                $stmtDeletePadron = $pdo->prepare("DELETE FROM padron_equipos WHERE venta_id = :venta_id_del AND venta_detalle_id IS NULL AND numero_serie IN ($listaPlaceholdersSeries)");
                $stmtDeletePadron->execute($paramsSeries);
            }

            $stmtDelete = $pdo->prepare("DELETE FROM venta_detalles WHERE id IN ($listaPlaceholders)");
            $stmtDelete->execute($paramsIds);
        }
    }

    // ==========================================
    // 3. SUBIR ARCHIVOS NUEVOS
    // ==========================================
    if (isset($_FILES['nuevos_facturas']) && !empty($_FILES['nuevos_facturas']['name'][0])) {
        
        $infoVenta = $pdo->query("SELECT folio, cliente FROM ventas WHERE id = $idVenta")->fetch(PDO::FETCH_ASSOC);
        $carpetaLimpia = preg_replace('/[^A-Za-z0-9_\-]/', '_', $infoVenta['cliente']);
        $uploadDir = "../uploads/ventas/{$carpetaLimpia}/";
        
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        $sqlArch = "INSERT INTO venta_archivos (venta_id, nombre_archivo, ruta_archivo, tipo_archivo) VALUES (?, ?, ?, ?)";
        $stmtArch = $pdo->prepare($sqlArch);

        foreach ($_FILES['nuevos_facturas']['name'] as $k => $nomOriginal) {
            if ($_FILES['nuevos_facturas']['error'][$k] === UPLOAD_ERR_OK) {
                $ext = pathinfo($nomOriginal, PATHINFO_EXTENSION);
                $tipo = $_FILES['nuevos_facturas']['type'][$k];
                $nuevoNombre = "{$infoVenta['folio']}_" . time() . "_{$k}.{$ext}";
                $rutaCompleta = $uploadDir . $nuevoNombre;

                if (move_uploaded_file($_FILES['nuevos_facturas']['tmp_name'][$k], $rutaCompleta)) {
                    $stmtArch->execute([$idVenta, $nomOriginal, $rutaCompleta, $tipo]);
                }
            }
        }
    }

    $pdo->commit();

    registrarAuditoria('ventas', (int)$idVenta, 'UPDATE', $ventaAntes, fotografiarVenta($pdo, (int)$idVenta));

    echo json_encode(['exito' => true, 'mensaje' => 'Venta actualizada correctamente con todos sus detalles.']);

} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("Error BD al actualizar venta: " . $e->getMessage());
    echo json_encode(['exito' => false, 'mensaje' => 'Error de BD: ' . $e->getMessage()]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['exito' => false, 'mensaje' => 'Error: ' . $e->getMessage()]);
}
?>