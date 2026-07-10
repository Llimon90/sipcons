<?php
require_once __DIR__ . '/../auth/middleware.php';
// backend/buscar-clientes.php
header('Content-Type: application/json');

// Desactivar la impresión de errores HTML de PHP para no romper el JSON
error_reporting(0);
ini_set('display_errors', 0);


// Si tu conexion.php solo declara las variables $host, $user... instanciamos la conexión aquí.
// Si ya trae $conn instanciado, usamos el existente.
if (!isset($conn) || $conn === null) {
    $conn = new mysqli($host, $user, $password, $database);
}

if ($conn->connect_error) {
    error_log("buscar-clientes.php: Error de conexión: " . $conn->connect_error);
    echo json_encode(["error" => "Error de conexión con el servidor"]);
    exit;
}

$filtro = isset($_GET['q']) ? trim($_GET['q']) : '';
$paramFiltro = "%$filtro%";

try {
    $sql = "SELECT * FROM clientes 
            WHERE nombre LIKE ? 
            OR rfc LIKE ? 
            OR direccion LIKE ? 
            OR telefono LIKE ? 
            OR contactos LIKE ? 
            OR email LIKE ?
            ORDER BY nombre";
            
    $stmt = $conn->prepare($sql);
    
    // Vinculamos 6 veces la variable $paramFiltro (una por cada '?')
    $stmt->bind_param("ssssss", $paramFiltro, $paramFiltro, $paramFiltro, $paramFiltro, $paramFiltro, $paramFiltro);
    
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        $clientes = [];
        
        while ($row = $result->fetch_assoc()) {
            $clientes[] = $row;
        }
        
        echo json_encode($clientes);
    } else {
        echo json_encode(['error' => 'Error al ejecutar la consulta']);
    }

    $stmt->close();
    $conn->close();

} catch (Exception $e) {
    error_log("buscar-clientes.php: " . $e->getMessage());
    echo json_encode(['error' => 'Error al procesar la solicitud']);
}
?>