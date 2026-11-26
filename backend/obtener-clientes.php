<?php

ob_start();

error_reporting(E_ALL);
ini_set('display_errors', 1);


require_once __DIR__ . '/../auth/middleware.php';


header('Content-Type: application/json');

if (!isset($conn)) {
    ob_clean(); // Limpiamos cualquier error previo
    die(json_encode(["error" => "La variable \$conn no existe. Revisa tu config/database.php"]));
}
if ($conn->connect_error) {
    ob_clean();
    die(json_encode(["error" => "Error de conexión BD: " . $conn->connect_error]));
}

$busqueda = isset($_GET['busqueda']) ? trim($_GET['busqueda']) : '';

if ($busqueda !== '') {
    $sql = "SELECT * FROM clientes WHERE
            nombre LIKE ? OR
            rfc LIKE ? OR
            direccion LIKE ? OR
            telefono LIKE ? OR
            contactos LIKE ? OR
            email LIKE ?
            ORDER BY nombre";
    $stmt = $conn->prepare($sql);
    $comodin = '%' . $busqueda . '%';
    $stmt->bind_param('ssssss', $comodin, $comodin, $comodin, $comodin, $comodin, $comodin);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = $conn->query("SELECT * FROM clientes ORDER BY nombre");
}

if (!$result) {
    ob_clean();
    die(json_encode(['error' => 'Error SQL: ' . $conn->error]));
}

$clientes = [];
while ($row = $result->fetch_assoc()) {
    $clientes[] = $row;
}

// LIMPIAMOS EL BÚFER POR COMPLETO (Borra cualquier "Warning" o espacio en blanco anterior)
ob_clean();

// AHORA SÍ, DEVOLVEMOS EL JSON PURO
echo json_encode($clientes);
$conn->close();
exit;
?>