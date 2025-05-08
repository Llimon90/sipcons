<!--  -->

<?php
$host = "localhost";
$user = "sipcons1_appweb";
$password = "sip*SYS2025";
$database = "sipcons1_appweb";

$conn = new mysqli($host, $user, $password, $database);
if ($conn->connect_error) {
    die(json_encode(['success' => false, 'message' => 'Error de conexión: ' . $conn->connect_error]));
}

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

$busqueda = isset($_GET['busqueda']) ? $conn->real_escape_string($_GET['busqueda']) : '';

$sql = "SELECT id, nombre, rfc, direccion, telefono, contactos, email FROM clientes";

if (!empty($busqueda)) {
    $sql .= " WHERE nombre LIKE '%$busqueda%' 
           OR rfc LIKE '%$busqueda%' 
           OR email LIKE '%$busqueda%' 
           OR telefono LIKE '%$busqueda%' 
           OR contactos LIKE '%$busqueda%'";
}

$sql .= " ORDER BY nombre ASC";

$resultado = $conn->query($sql);

$clientes = [];
while ($fila = $resultado->fetch_assoc()) {
    $clientes[] = $fila;
}

echo json_encode($clientes);

$conn->close();
