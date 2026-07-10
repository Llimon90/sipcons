<?php
require_once __DIR__ . '/../auth/middleware.php';
header("Content-Type: application/json; charset=UTF-8");


try {
    $sql = "SELECT * FROM ventas ORDER BY fecha_registro DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $ventas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['exito' => true, 'ventas' => $ventas]);
} catch (PDOException $e) {
    error_log("obtener-ventas.php: " . $e->getMessage());
    echo json_encode(['exito' => false, 'mensaje' => 'Error al obtener ventas']);
}