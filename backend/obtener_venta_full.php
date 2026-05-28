<?php
header('Content-Type: application/json');
require_once 'conexion.php';

$id = $_GET['id'] ?? null;
if (!$id) die(json_encode(['exito' => false, 'mensaje' => 'ID no proporcionado']));

try {
    // 1. Cabecera
    $stmtV = $pdo->prepare("SELECT * FROM ventas WHERE id = ?");
    $stmtV->execute([$id]);
    $venta = $stmtV->fetch(PDO::FETCH_ASSOC);

    // 2. Series (Detalles)
    $stmtD = $pdo->prepare("SELECT * FROM venta_detalles WHERE venta_id = ?");
    $stmtD->execute([$id]);
    $series = $stmtD->fetchAll(PDO::FETCH_ASSOC);

    // 3. Archivos
    $stmtA = $pdo->prepare("SELECT * FROM venta_archivos WHERE venta_id = ?");
    $stmtA->execute([$id]);
    $archivos = $stmtA->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'exito' => true,
        'venta' => $venta,
        'series' => $series,
        'archivos' => $archivos
    ]);
} catch (Exception $e) {
    echo json_encode(['exito' => false, 'mensaje' => $e->getMessage()]);
}