<?php
header('Content-Type: application/json');
require_once 'conexion.php';

$cliente = $_GET['cliente'] ?? '';

if (empty($cliente)) {
    echo json_encode([]);
    exit;
}

try {
    $sql = "SELECT * FROM padron_equipos WHERE cliente = ? ORDER BY id DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$cliente]);
    $equipos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($equipos);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?>