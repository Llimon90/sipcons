<?php
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../auth/permisos.php';
requirePermisoAlguno('ventas');
require_once __DIR__ . '/../auth/audit.php';
require_once __DIR__ . '/lib/subidas.php';
require_once __DIR__ . '/lib/inventario_helpers.php';
require_once __DIR__ . '/lib/periodicidad_helpers.php';
require_once __DIR__ . '/../config/branding.php';
header('Content-Type: application/json');

try {
    $pdo->beginTransaction();

    // C-H03: el folio se toma primero con el contador bloqueado; así dos ventas
    // simultáneas no repiten folio ni pasan la validación de series al mismo tiempo.
    $nFolio = tomarFolio($pdo, 'ventas', 'ventas', 'folio', fn(int $n) => 'VT-' . str_pad((string)$n, 5, '0', STR_PAD_LEFT));

    $cliente = $_POST['cliente'] ?? throw new Exception("Cliente no especificado");
    $series = json_decode($_POST['series'], true);
    
    if (!is_array($series) || count($series) === 0) {
        throw new Exception("Debes ingresar al menos un número de serie.");
    }

    $fecha_venta = !empty($_POST['fecha_venta']) ? $_POST['fecha_venta'] : date('Y-m-d');

    // Artículo de inventario vendido (opcional): cada serie descuenta una unidad
    $articuloId = (int)($_POST['articulo_id'] ?? 0) ?: null;

    // =========================================================================
    // SÚPER CANDADO: Validar todas las series antes de procesar la venta
    // =========================================================================
    $seriesEnFormulario = [];
    foreach ($series as $s) {
        $serieLimpia = trim($s);
        $serieUpper = strtoupper($serieLimpia);

        if ($serieUpper !== '' && $serieUpper !== 'S/N' && $serieUpper !== 'N/A') {
            
            // 1. Validar que no haya escrito la misma serie 2 veces en este mismo formulario
            if (in_array($serieUpper, $seriesEnFormulario)) {
                throw new Exception("Error: Has introducido la serie '$serieLimpia' más de una vez en esta misma venta.");
            }
            $seriesEnFormulario[] = $serieUpper;

            // 2. Validar contra toda la base de datos (Padrón de Equipos)
            $stmtCheck = $pdo->prepare("SELECT cliente, sucursal FROM padron_equipos WHERE numero_serie = ? LIMIT 1");
            $stmtCheck->execute([$serieLimpia]);
            if ($equipoExistente = $stmtCheck->fetch(PDO::FETCH_ASSOC)) {
                $lugar = $equipoExistente['sucursal'] ? " ({$equipoExistente['sucursal']})" : "";
                throw new Exception("Error: La serie '$serieLimpia' ya está registrada en un equipo de {$equipoExistente['cliente']}$lugar. Venta cancelada.");
            }
        }
    }
    // =========================================================================

    // 1. El folio ya se tomó al inicio (C-H03)

    // 2. Insertar Cabecera (Venta)
    $sqlV = "INSERT INTO ventas (folio, cliente, sucursal, fecha_registro) VALUES (?, ?, ?, ?)";
    $stmtV = $pdo->prepare($sqlV);
    $stmtV->execute([$nFolio, $cliente, $_POST['sucursal'], $fecha_venta]);
    $venta_id = $pdo->lastInsertId();

    // 3. Procesar Archivos (Facturas)
    $archivosAgregados = [];
    if (isset($_FILES['facturas']) && !empty($_FILES['facturas']['name'][0])) {
        $carpetaLimpia = preg_replace('/[^A-Za-z0-9_\-]/', '_', $cliente);
        $uploadDir = '../' . carpetaEmpresa('uploads') . "/ventas/{$carpetaLimpia}/";
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

        $sqlArch = "INSERT INTO venta_archivos (venta_id, nombre_archivo, ruta_archivo, tipo_archivo) VALUES (?, ?, ?, ?)";
        $stmtArch = $pdo->prepare($sqlArch);

        foreach ($_FILES['facturas']['name'] as $k => $nomOriginal) {
            if ($_FILES['facturas']['error'][$k] === UPLOAD_ERR_OK && extensionAdjuntoPermitida($nomOriginal)) {
                $ext = pathinfo($nomOriginal, PATHINFO_EXTENSION);
                $tipo = $_FILES['facturas']['type'][$k];
                $nuevoNombre = "{$nFolio}_" . time() . "_{$k}.{$ext}";
                $rutaCompleta = $uploadDir . $nuevoNombre;

                if (move_uploaded_file($_FILES['facturas']['tmp_name'][$k], $rutaCompleta)) {
                    $stmtArch->execute([$venta_id, $nomOriginal, $rutaCompleta, $tipo]);
                    $archivosAgregados[] = $nomOriginal;
                }
            }
        }
    }

    // 4. PREPARAR INSERCIONES
    [$mesesCalibracion, $tieneServicio, $mesesServicio] = normalizarPeriodicidad($_POST);
    $mesesGarantia = (int)($_POST['garantia'] ?? 0);

    $fechaProximaCalibracion = $mesesCalibracion > 0 ? date('Y-m-d', strtotime("$fecha_venta +$mesesCalibracion months")) : null;
    $fechaProximoServicio = ($tieneServicio && $mesesServicio > 0) ? date('Y-m-d', strtotime("$fecha_venta +$mesesServicio months")) : null;

    // A. Insertar en detalles de venta (Historial financiero)
    $sqlD = "INSERT INTO venta_detalles 
             (venta_id, articulo_id, equipo, marca, modelo, numero_serie, garantia, calibracion, servicio, frecuencia_servicio, notas, proxima_calibracion, proximo_servicio) 
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $stmtD = $pdo->prepare($sqlD);

    // B. Insertar directo en el Padrón de Equipos
    // venta_detalle_id enlaza el equipo con su fila exacta en venta_detalles, para
    // poder ubicarlo sin ambigüedad al editar la venta (dos equipos sin serie real
    // pueden compartir numero_serie = 'S/N' dentro de la misma venta).
    $sqlPadron = "INSERT INTO padron_equipos
                 (cliente, sucursal, equipo, marca, modelo, numero_serie, calibracion, servicio, frecuencia_servicio, garantia, proxima_calibracion, proximo_servicio, origen, venta_id, venta_detalle_id, fecha_registro)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Venta propia', ?, ?, ?)";
    $stmtPadron = $pdo->prepare($sqlPadron);

    $equiposAuditoria = [];

    foreach ($series as $s) {
        $serieLimpia = trim($s);

        // Guardar detalle de venta
        $stmtD->execute([
            $venta_id, $articuloId, $_POST['equipo'], $_POST['marca'], $_POST['modelo'], $serieLimpia,
            $mesesGarantia, $mesesCalibracion, $tieneServicio, $mesesServicio,
            $_POST['notas'], $fechaProximaCalibracion, $fechaProximoServicio
        ]);
        $detalle_id = $pdo->lastInsertId();

        // Guardar en Padrón
        $stmtPadron->execute([
            $cliente, $_POST['sucursal'], $_POST['equipo'], $_POST['marca'], $_POST['modelo'], $serieLimpia,
            $mesesCalibracion, $tieneServicio, $mesesServicio, $mesesGarantia,
            $fechaProximaCalibracion, $fechaProximoServicio, $venta_id, $detalle_id, $fecha_venta
        ]);

        $equiposAuditoria[] = [
            'venta_detalle_id' => $detalle_id,
            'numero_serie'     => $serieLimpia,
        ];
    }

    // Descuenta del inventario las unidades vendidas (falla toda la venta si no hay existencia)
    if ($articuloId) {
        invSincronizarVenta($pdo, (int)$venta_id, $nFolio);
    }

    // Venta que viene de una cotización aprobada: queda ligada y, si era la
    // última por registrar, la cotización pasa a "convertida"
    $cotizacionVentaId = (int)($_POST['cotizacion_venta_id'] ?? 0);
    if ($cotizacionVentaId) {
        require_once __DIR__ . '/lib/cotizaciones_helpers.php';
        cotMarcarVentaRegistrada($pdo, $cotizacionVentaId, (int)$venta_id, $nFolio);
    }

    // D-44: la venta y sus equipos del padrón se ligan a la sucursal del cliente
    require_once __DIR__ . '/lib/sucursales_helpers.php';
    sucSincronizarPorNombre($pdo, (string)$cliente);

    $pdo->commit();
    solicitarRevisionTickets();

    $datosVentaNueva = [
        'folio'               => $nFolio,
        'cliente'             => $cliente,
        'sucursal'            => $_POST['sucursal'],
        'fecha_venta'         => $fecha_venta,
        'equipo'              => $_POST['equipo'],
        'marca'               => $_POST['marca'],
        'modelo'              => $_POST['modelo'],
        'garantia'            => $mesesGarantia,
        'calibracion'         => $mesesCalibracion,
        'servicio'            => $tieneServicio,
        'frecuencia_servicio' => $mesesServicio,
        'equipos'             => $equiposAuditoria,
    ];
    if ($articuloId) {
        $datosVentaNueva['articulo_inventario_id'] = $articuloId;
    }
    if ($archivosAgregados) {
        $datosVentaNueva['archivos_agregados'] = $archivosAgregados;
    }
    registrarAuditoria('ventas', $venta_id, 'CREATE', null, $datosVentaNueva, $nFolio);

    echo json_encode(['exito' => true, 'folio' => $nFolio]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['exito' => false, 'mensaje' => mensajeErrorPublico($e)]);
}
?>