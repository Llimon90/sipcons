<?php
header('Content-Type: application/json');
require 'conexion.php';

$id = $_GET['id'] ?? null;

if (!$id) {
    echo json_encode(['exito' => false, 'mensaje' => 'ID no especificado']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT * FROM ventas WHERE id = ?");
    $stmt->execute([$id]);
    $venta = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$venta) {
        echo json_encode(['exito' => false, 'mensaje' => 'Venta no encontrada']);
        exit;
    }
    
    echo json_encode(['exito' => true, 'venta' => $venta]);
} catch (PDOException $e) {
    echo json_encode(['exito' => false, 'mensaje' => 'Error: ' . $e->getMessage()]);
}
?>