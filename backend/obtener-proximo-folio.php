<?php
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../auth/permisos.php';
requirePermisoAlguno('ventas');
header('Content-Type: application/json');

// Solo letras y números: el prefijo se usa en un LIKE
$prefijo = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)($_GET['prefijo'] ?? 'VT'))) ?: 'VT';

try {
    // Obtener el último folio de la base de datos
    $stmt = $pdo->prepare("SELECT folio FROM ventas WHERE folio LIKE ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$prefijo . '-%']);
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
        'mensaje' => 'Error al generar folio: ' . mensajeErrorPublico($e)
    ]);
}
?>