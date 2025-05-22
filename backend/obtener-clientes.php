<?php
// Asegurar que el contenido devuelto sea JSON
header('Content-Type: application/json');
error_reporting(0);
ini_set('display_errors', 0);

$host = "localhost";
$user = "sipcons1_appweb";
$password = "sip*SYS2025";
$database = "sipcons1_appweb";

$conn = new mysqli($host, $user, $password, $database);

if ($conn->connect_error) {
    echo json_encode(['error' => 'Error de conexión: ' . $conn->connect_error]);
    exit;
}

// Obtener el parámetro de búsqueda si existe
$busqueda = isset($_GET['busqueda']) ? $conn->real_escape_string($_GET['busqueda']) : '';

// Preparar la consulta SQL
if ($busqueda) {
    $sql = "SELECT * FROM clientes WHERE 
            nombre LIKE '%$busqueda%' OR 
            rfc LIKE '%$busqueda%' OR 
            direccion LIKE '%$busqueda%' OR 
            telefono LIKE '%$busqueda%' OR 
            contactos LIKE '%$busqueda%' OR 
            email LIKE '%$busqueda%' 
            ORDER BY nombre";
} else {
    $sql = "SELECT * FROM clientes ORDER BY nombre";
}

// Ejecutar la consulta
$result = $conn->query($sql);

// Verificar si la consulta fue exitosa
if (!$result) {
    echo json_encode(['success' => false, 'message' => 'Error en la consulta: ' . $conn->error]);
    $conn->close();
    exit;
}

// Obtener los resultados
$clientes = [];
while ($row = $result->fetch_assoc()) {
    $clientes[] = $row;
}

// Devolver los resultados en formato JSON
echo json_encode($clientes);

// Cerrar la conexión
$conn->close();
exit;
?>
