<?php
require_once __DIR__ . '/../auth/middleware.php';
header('Content-Type: application/json');

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
    error_log("obtener_equipos_cliente.php: " . $e->getMessage());
    echo json_encode(['error' => 'Error al obtener los equipos del cliente']);
}
?>