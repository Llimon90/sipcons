<?php
header('Content-Type: application/json');
require 'conexion.php';

$idVenta = $_POST['venta_id'] ?? null;

if (!$idVenta) {
    echo json_encode(['exito' => false, 'mensaje' => 'ID de venta inválido o no recibido']);
    exit;
}

try {
    $pdo->beginTransaction();

    // ==========================================
    // 1. ACTUALIZAR CABECERA (Tabla: ventas)
    // ==========================================
    $stmtV = $pdo->prepare("UPDATE ventas SET 
        cliente = ?, 
        sucursal = ?,
        fecha_actualizacion = NOW() 
        WHERE id = ?");
    
    $stmtV->execute([
        $_POST['cliente'] ?? '',
        $_POST['sucursal'] ?? '',
        $idVenta
    ]);

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
            foreach ($seriesRecibidas as $s) {
                if (!empty($s['id_detalle']) && $s['id_detalle'] !== 'nuevo') {
                    // ACTUALIZAR serie existente
                    $idsQueSeQuedan[] = $s['id_detalle'];
                    $stmtUpdate->execute([
                        $_POST['equipo'], $_POST['marca'], $_POST['modelo'],
                        $s['serie'],
                        $_POST['garantia'] ?? 0, $_POST['calibracion'] ?? 0,
                        $tieneServicio, $frecuencia, $_POST['notas'] ?? '',
                        $s['id_detalle']
                    ]);
                } else {
                    // INSERTAR serie nueva (si la cantidad de equipos aumentó)
                    $stmtInsert->execute([
                        $idVenta,
                        $_POST['equipo'], $_POST['marca'], $_POST['modelo'],
                        $s['serie'],
                        $_POST['garantia'] ?? 0, $_POST['calibracion'] ?? 0,
                        $tieneServicio, $frecuencia, $_POST['notas'] ?? ''
                    ]);
                }
            }
        }

        // Eliminar las series que se quitaron en pantalla al reducir la cantidad
        $idsAEliminar = array_diff($idsActuales, $idsQueSeQuedan);
        if (!empty($idsAEliminar)) {
            $placeholders = implode(',', array_fill(0, count($idsAEliminar), '?'));
            $stmtDelete = $pdo->prepare("DELETE FROM venta_detalles WHERE id IN ($placeholders)");
            $stmtDelete->execute($idsAEliminar);
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