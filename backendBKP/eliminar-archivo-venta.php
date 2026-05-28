<?php
header('Content-Type: application/json');
require 'conexion.php';

$data = json_decode(file_get_contents('php://input'), true);

if (!$data || !isset($data['id'])) {
    echo json_encode(['exito' => false, 'mensaje' => 'Datos inválidos']);
    exit;
}

try {
    // Obtener información del archivo antes de borrar
    $stmt = $pdo->prepare("SELECT * FROM archivos_ventas WHERE id = ?");
    $stmt->execute([$data['id']]);
    $archivo = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$archivo) {
        echo json_encode(['exito' => false, 'mensaje' => 'Archivo no encontrado']);
        exit;
    }
    
    // Eliminar archivo físico
    if (file_exists($archivo['ruta'])) {
        unlink($archivo['ruta']);
    }
    
    // Eliminar registro de la base de datos
    $stmt = $pdo->prepare("DELETE FROM archivos_ventas WHERE id = ?");
    $stmt->execute([$data['id']]);
    
    echo json_encode(['exito' => true, 'mensaje' => 'Archivo eliminado correctamente']);
} catch (PDOException $e) {
    echo json_encode(['exito' => false, 'mensaje' => 'Error: ' . $e->getMessage()]);
}
?>