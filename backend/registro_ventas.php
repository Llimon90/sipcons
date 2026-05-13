<?php
header('Content-Type: application/json');
require_once 'conexion.php';

try {
    $pdo->beginTransaction();

    $cliente = $_POST['cliente'] ?? throw new Exception("Cliente no especificado");
    $series = json_decode($_POST['series'], true);
    
    if (!is_array($series) || count($series) === 0) {
        throw new Exception("Debes ingresar al menos un número de serie.");
    }

    $fecha_venta = !empty($_POST['fecha_venta']) ? $_POST['fecha_venta'] : date('Y-m-d');

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

    // 1. Generar Folio
    $stmtF = $pdo->query("SELECT folio FROM ventas ORDER BY id DESC LIMIT 1");
    $uFolio = $stmtF->fetchColumn();
    $nFolio = "VT-" . str_pad($uFolio ? (int)substr($uFolio, 3) + 1 : 1, 5, "0", STR_PAD_LEFT);

    // 2. Insertar Cabecera (Venta)
    $sqlV = "INSERT INTO ventas (folio, cliente, sucursal, fecha_registro) VALUES (?, ?, ?, ?)";
    $stmtV = $pdo->prepare($sqlV);
    $stmtV->execute([$nFolio, $cliente, $_POST['sucursal'], $fecha_venta]);
    $venta_id = $pdo->lastInsertId();

    // 3. Procesar Archivos (Facturas)
    if (isset($_FILES['facturas']) && !empty($_FILES['facturas']['name'][0])) {
        $carpetaLimpia = preg_replace('/[^A-Za-z0-9_\-]/', '_', $cliente);
        $uploadDir = "../uploads/ventas/{$carpetaLimpia}/";
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

        $sqlArch = "INSERT INTO venta_archivos (venta_id, nombre_archivo, ruta_archivo, tipo_archivo) VALUES (?, ?, ?, ?)";
        $stmtArch = $pdo->prepare($sqlArch);

        foreach ($_FILES['facturas']['name'] as $k => $nomOriginal) {
            if ($_FILES['facturas']['error'][$k] === UPLOAD_ERR_OK) {
                $ext = pathinfo($nomOriginal, PATHINFO_EXTENSION);
                $tipo = $_FILES['facturas']['type'][$k];
                $nuevoNombre = "{$nFolio}_" . time() . "_{$k}.{$ext}";
                $rutaCompleta = $uploadDir . $nuevoNombre;

                if (move_uploaded_file($_FILES['facturas']['tmp_name'][$k], $rutaCompleta)) {
                    $stmtArch->execute([$venta_id, $nomOriginal, $rutaCompleta, $tipo]);
                }
            }
        }
    }

    // 4. PREPARAR INSERCIONES
    $mesesCalibracion = (int)($_POST['calibracion'] ?? 0);
    $mesesServicio = (int)($_POST['frecuencia_servicio'] ?? 0);
    $tieneServicio = !empty($_POST['servicio']) ? 1 : 0;
    $mesesGarantia = (int)($_POST['garantia'] ?? 0);

    $fechaProximaCalibracion = $mesesCalibracion > 0 ? date('Y-m-d', strtotime("$fecha_venta +$mesesCalibracion months")) : null;
    $fechaProximoServicio = ($tieneServicio && $mesesServicio > 0) ? date('Y-m-d', strtotime("$fecha_venta +$mesesServicio months")) : null;

    // A. Insertar en detalles de venta (Historial financiero)
    $sqlD = "INSERT INTO venta_detalles 
             (venta_id, equipo, marca, modelo, numero_serie, garantia, calibracion, servicio, frecuencia_servicio, notas, proxima_calibracion, proximo_servicio) 
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $stmtD = $pdo->prepare($sqlD);

    // B. Insertar directo en el Padrón de Equipos
    $sqlPadron = "INSERT INTO padron_equipos 
                 (cliente, sucursal, equipo, marca, modelo, numero_serie, calibracion, servicio, frecuencia_servicio, garantia, proxima_calibracion, proximo_servicio, origen, venta_id, fecha_registro) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Venta Lumina', ?, ?)";
    $stmtPadron = $pdo->prepare($sqlPadron);
    
    foreach ($series as $s) {
        $serieLimpia = trim($s);
        
        // Guardar detalle de venta
        $stmtD->execute([
            $venta_id, $_POST['equipo'], $_POST['marca'], $_POST['modelo'], $serieLimpia, 
            $mesesGarantia, $mesesCalibracion, $tieneServicio, $mesesServicio, 
            $_POST['notas'], $fechaProximaCalibracion, $fechaProximoServicio
        ]);

        // Guardar en Padrón
        $stmtPadron->execute([
            $cliente, $_POST['sucursal'], $_POST['equipo'], $_POST['marca'], $_POST['modelo'], $serieLimpia, 
            $mesesCalibracion, $tieneServicio, $mesesServicio, $mesesGarantia, 
            $fechaProximaCalibracion, $fechaProximoServicio, $venta_id, $fecha_venta
        ]);
    }

    $pdo->commit();
    echo json_encode(['exito' => true, 'folio' => $nFolio]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['exito' => false, 'mensaje' => $e->getMessage()]);
}
?>