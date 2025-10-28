<?php
include 'conexion.php';

header('Content-Type: application/json');

try {
    // Obtener marcas
    $stmt = $conn->query("SELECT * FROM marcas ORDER BY nombre");
    $marcas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Obtener modelos
    $stmt = $conn->query("
        SELECT m.*, mar.nombre as marca_nombre 
        FROM modelos m 
        JOIN marcas mar ON m.marca_id = mar.id 
        ORDER BY mar.nombre, m.nombre
    ");
    $modelos_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Obtener documentos
    $stmt = $conn->query("SELECT * FROM documentos");
    $documentos_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Estructurar los datos como espera el JavaScript
    $modelos = [];
    $documentos = [];
    
    foreach ($modelos_data as $modelo) {
        $modelos[$modelo['marca_id']][] = [
            'id' => $modelo['id'],
            'nombre' => $modelo['nombre'],
            'tipo' => $modelo['tipo']
        ];
    }
    
    foreach ($documentos_data as $doc) {
        $documentos[$doc['modelo_id']][] = [
            'tipo' => $doc['tipo'],
            'nombre' => $doc['nombre'],
            'archivo' => $doc['archivo']
        ];
    }
    
    echo json_encode([
        'marcas' => $marcas,
        'modelos' => $modelos,
        'documentos' => $documentos
    ]);
    
} catch(PDOException $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?>