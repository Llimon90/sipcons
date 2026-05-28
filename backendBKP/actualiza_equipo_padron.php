<?php
header('Content-Type: application/json');
require_once 'conexion.php';

try {
    $id = $_POST['id'] ?? throw new Exception("ID de equipo no especificado");
    $sucursal = $_POST['sucursal'] ?? '';
    $equipo = $_POST['equipo'] ?? '';
    $marca = $_POST['marca'] ?? '';
    $modelo = $_POST['modelo'] ?? '';
    $numero_serie = $_POST['numero_serie'] ?? 'S/N';
    $fecha_registro = !empty($_POST['fecha_registro']) ? $_POST['fecha_registro'] : date('Y-m-d');
    
    $mesesCalibracion = (int)($_POST['calibracion'] ?? 0);
    $tieneServicio = !empty($_POST['servicio']) ? 1 : 0;
    $mesesServicio = (int)($_POST['frecuencia_servicio'] ?? 0);
    $mesesGarantia = (int)($_POST['garantia'] ?? 0);

    // Calculamos los vencimientos base actualizados
    $fechaProximaCalibracion = $mesesCalibracion > 0 ? date('Y-m-d', strtotime("$fecha_registro +$mesesCalibracion months")) : null;
    $fechaProximoServicio = ($tieneServicio && $mesesServicio > 0) ? date('Y-m-d', strtotime("$fecha_registro +$mesesServicio months")) : null;

    $sql = "UPDATE padron_equipos SET 
            sucursal = ?, equipo = ?, marca = ?, modelo = ?, numero_serie = ?, 
            calibracion = ?, servicio = ?, frecuencia_servicio = ?, garantia = ?, 
            proxima_calibracion = ?, proximo_servicio = ?, fecha_registro = ? 
            WHERE id = ?";
            
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $sucursal, $equipo, $marca, $modelo, $numero_serie, 
        $mesesCalibracion, $tieneServicio, $mesesServicio, $mesesGarantia, 
        $fechaProximaCalibracion, $fechaProximoServicio, $fecha_registro, $id
    ]);

    echo json_encode(['exito' => true, 'mensaje' => 'Equipo actualizado con éxito.']);

} catch (Exception $e) {
    echo json_encode(['exito' => false, 'mensaje' => $e->getMessage()]);
}
?>