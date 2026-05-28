<?php
header('Content-Type: application/json');
require_once 'conexion.php';

try {
    $cliente = $_POST['cliente'] ?? throw new Exception("Cliente no especificado");
    $sucursal = $_POST['sucursal'] ?? '';
    $equipo = $_POST['equipo'] ?? throw new Exception("Equipo no especificado");
    $marca = $_POST['marca'] ?? '';
    $modelo = $_POST['modelo'] ?? '';
    $numero_serie = trim($_POST['numero_serie'] ?? 'S/N');
    
    // =========================================================================
    // CANDADO DE DUPLICADOS: Evita series repetidas (Ignora "S/N" o "N/A")
    // =========================================================================
    $serieUpper = strtoupper($numero_serie);
    if ($serieUpper !== '' && $serieUpper !== 'S/N' && $serieUpper !== 'N/A') {
        $stmtCheck = $pdo->prepare("SELECT cliente, sucursal FROM padron_equipos WHERE numero_serie = ? LIMIT 1");
        $stmtCheck->execute([$numero_serie]);
        if ($equipoExistente = $stmtCheck->fetch(PDO::FETCH_ASSOC)) {
            $lugar = $equipoExistente['sucursal'] ? " ({$equipoExistente['sucursal']})" : "";
            throw new Exception("La serie '$numero_serie' ya pertenece a un equipo de {$equipoExistente['cliente']}$lugar.");
        }
    }
    // =========================================================================

    $fecha_registro = !empty($_POST['fecha_registro']) ? $_POST['fecha_registro'] : date('Y-m-d');
    
    $mesesCalibracion = (int)($_POST['calibracion'] ?? 0);
    $tieneServicio = !empty($_POST['servicio']) ? 1 : 0;
    $mesesServicio = (int)($_POST['frecuencia_servicio'] ?? 0);
    $mesesGarantia = (int)($_POST['garantia'] ?? 0);

    $fechaProximaCalibracion = $mesesCalibracion > 0 ? date('Y-m-d', strtotime("$fecha_registro +$mesesCalibracion months")) : null;
    $fechaProximoServicio = ($tieneServicio && $mesesServicio > 0) ? date('Y-m-d', strtotime("$fecha_registro +$mesesServicio months")) : null;

    $sql = "INSERT INTO padron_equipos 
            (cliente, sucursal, equipo, marca, modelo, numero_serie, calibracion, servicio, frecuencia_servicio, garantia, proxima_calibracion, proximo_servicio, origen, fecha_registro) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Externo', ?)";
            
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $cliente, $sucursal, $equipo, $marca, $modelo, $numero_serie, 
        $mesesCalibracion, $tieneServicio, $mesesServicio, $mesesGarantia, 
        $fechaProximaCalibracion, $fechaProximoServicio, $fecha_registro
    ]);

    echo json_encode(['exito' => true, 'mensaje' => 'Equipo externo registrado en el Padrón con éxito.']);

} catch (Exception $e) {
    echo json_encode(['exito' => false, 'mensaje' => $e->getMessage()]);
}
?>