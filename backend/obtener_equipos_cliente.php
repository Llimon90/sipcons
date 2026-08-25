<?php
require_once __DIR__ . '/../auth/middleware.php';
header('Content-Type: application/json');

$cliente = $_GET['cliente'] ?? '';

if (empty($cliente)) {
    echo json_encode([]);
    exit;
}

try {
    // LEFT JOIN a ventas para mostrar el folio real (VT-00001), no el id interno,
    // y que la nomenclatura coincida con la que se ve en la tabla de Ventas.
    $sql = "SELECT p.*, v.folio AS venta_folio
            FROM padron_equipos p
            LEFT JOIN ventas v ON p.venta_id = v.id
            WHERE p.cliente = ?
            ORDER BY p.id DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$cliente]);
    $equipos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($equipos);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?>