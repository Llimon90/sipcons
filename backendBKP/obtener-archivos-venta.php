<?php
header('Content-Type: application/json');
require 'conexion.php';

$venta_id = $_GET['venta_id'] ?? null;

if (!$venta_id) {
    echo json_encode(['exito' => false, 'mensaje' => 'ID de venta no especificado']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT * FROM archivos_ventas WHERE venta_id = ?");
    $stmt->execute([$venta_id]);
    $archivos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['exito' => true, 'archivos' => $archivos]);
} catch (PDOException $e) {
    echo json_encode(['exito' => false, 'mensaje' => 'Error: ' . $e->getMessage()]);
}
?>