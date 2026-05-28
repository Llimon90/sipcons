<?php
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET");

require_once 'conexion.php';

try {
    $sql = "SELECT * FROM ventas ORDER BY fecha_registro DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $ventas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['exito' => true, 'ventas' => $ventas]);
} catch (PDOException $e) {
    echo json_encode(['exito' => false, 'mensaje' => 'Error al obtener ventas: ' . $e->getMessage()]);
}