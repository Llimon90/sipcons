<?php
header('Content-Type: application/json');
require_once 'conexion.php';

try {
    $pdo->beginTransaction();

    $cliente = $_POST['cliente'] ?? throw new Exception("Cliente no especificado");
    $series = json_decode($_POST['series'], true);
    
    // CAPTURAMOS LA FECHA DEL FRONTEND (Si no viene, usamos hoy)
    $fecha_venta = !empty($_POST['fecha_venta']) ? $_POST['fecha_venta'] : date('Y-m-d');

    // 1. Generar Folio
    $stmtF = $pdo->query("SELECT folio FROM ventas ORDER BY id DESC LIMIT 1");
    $uFolio = $stmtF->fetchColumn();
    $nFolio = "VT-" . str_pad($uFolio ? (int)substr($uFolio, 3) + 1 : 1, 5, "0", STR_PAD_LEFT);

    // 2. Insertar Cabecera (Venta) - CAMBIADO NOW() POR ? PARA USAR LA FECHA REAL
    $sqlV = "INSERT INTO ventas (folio, cliente, sucursal, fecha_registro) VALUES (?, ?, ?, ?)";
    $stmtV = $pdo->prepare($sqlV);
    $stmtV->execute([$nFolio, $cliente, $_POST['sucursal'], $fecha_venta]);
    $venta_id = $pdo->lastInsertId();

    // 3. Procesar Archivos
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

    // 4. Insertar Detalles (Series) y CALCULAR VENCIMIENTOS BASADOS EN FECHA DE VENTA
    $mesesCalibracion = (int)($_POST['calibracion'] ?? 0);
    $mesesServicio = (int)($_POST['frecuencia_servicio'] ?? 0);
    $tieneServicio = !empty($_POST['servicio']) ? 1 : 0;

    // Calculamos basándonos en $fecha_venta
    $fechaProximaCalibracion = $mesesCalibracion > 0 ? date('Y-m-d', strtotime("$fecha_venta +$mesesCalibracion months")) : null;
    $fechaProximoServicio = ($tieneServicio && $mesesServicio > 0) ? date('Y-m-d', strtotime("$fecha_venta +$mesesServicio months")) : null;

    $sqlD = "INSERT INTO venta_detalles 
             (venta_id, equipo, marca, modelo, numero_serie, garantia, calibracion, servicio, frecuencia_servicio, notas, proxima_calibracion, proximo_servicio) 
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $stmtD = $pdo->prepare($sqlD);
    
    foreach ($series as $s) {
        $stmtD->execute([
            $venta_id, 
            $_POST['equipo'], 
            $_POST['marca'], 
            $_POST['modelo'], 
            $s, 
            $_POST['garantia'] ?: 0, 
            $mesesCalibracion, 
            $tieneServicio, 
            $mesesServicio, 
            $_POST['notas'],
            $fechaProximaCalibracion, 
            $fechaProximoServicio
        ]);
    }

    $pdo->commit();
    echo json_encode(['exito' => true, 'folio' => $nFolio]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['exito' => false, 'mensaje' => $e->getMessage()]);
}
?>