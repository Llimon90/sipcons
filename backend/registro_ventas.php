<?php
header('Content-Type: application/json');
require_once 'conexion.php';

try {
    $pdo->beginTransaction();

    $cliente = $_POST['cliente'] ?? throw new Exception("Cliente no especificado");
    $series = json_decode($_POST['series'], true);

    // 1. Generar Folio
    $stmtF = $pdo->query("SELECT folio FROM ventas ORDER BY id DESC LIMIT 1");
    $uFolio = $stmtF->fetchColumn();
    $nFolio = "VT-" . str_pad($uFolio ? (int)substr($uFolio, 3) + 1 : 1, 5, "0", STR_PAD_LEFT);

    // 2. Insertar Cabecera (Venta)
    $sqlV = "INSERT INTO ventas (folio, cliente, sucursal, fecha_registro) VALUES (?, ?, ?, NOW())";
    $stmtV = $pdo->prepare($sqlV);
    $stmtV->execute([$nFolio, $cliente, $_POST['sucursal']]);
    $venta_id = $pdo->lastInsertId();

    // 3. PROCESAR ARCHIVOS E INSERTAR EN venta_archivos
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
                    // AQUÍ se llena tu tabla venta_archivos
                    $stmtArch->execute([$venta_id, $nomOriginal, $rutaCompleta, $tipo]);
                }
            }
        }
    }

    // 4. Insertar Detalles (Series)
    $sqlD = "INSERT INTO venta_detalles (venta_id, equipo, marca, modelo, numero_serie, garantia, servicio, notas) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
    $stmtD = $pdo->prepare($sqlD);
    foreach ($series as $s) {
        $stmtD->execute([
            $venta_id, $_POST['equipo'], $_POST['marca'], $_POST['modelo'], 
            $s, $_POST['garantia'], $_POST['servicio'], $_POST['notas']
        ]);
    }

    $pdo->commit();
    echo json_encode(['exito' => true, 'folio' => $nFolio]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['exito' => false, 'mensaje' => $e->getMessage()]);
}