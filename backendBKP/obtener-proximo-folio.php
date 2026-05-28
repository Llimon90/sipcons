<?php
header('Content-Type: application/json');
require 'conexion.php';

$prefijo = isset($_GET['prefijo']) ? $_GET['prefijo'] : 'VT';

try {
    // Obtener el último folio de la base de datos
    $stmt = $pdo->query("SELECT folio FROM ventas WHERE folio LIKE '$prefijo-%' ORDER BY id DESC LIMIT 1");
    $ultimoFolio = $stmt->fetchColumn();
    
    if ($ultimoFolio) {
        // Extraer el número y incrementarlo
        $numero = intval(substr($ultimoFolio, strpos($ultimoFolio, '-') + 1));
        $nuevoNumero = $numero + 1;
    } else {
        // Si no hay folios, empezar en 1
        $nuevoNumero = 1;
    }
    
    // Formatear el nuevo folio con ceros a la izquierda (VT-0001)
    $nuevoFolio = $prefijo . '-' . str_pad($nuevoNumero, 4, '0', STR_PAD_LEFT);
    
    echo json_encode([
        'exito' => true,
        'folio' => $nuevoFolio
    ]);
} catch (PDOException $e) {
    echo json_encode([
        'exito' => false,
        'mensaje' => 'Error al generar folio: ' . $e->getMessage()
    ]);
}
?>