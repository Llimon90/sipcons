<?php
require_once __DIR__ . '/../auth/middleware.php';

if ($conn->connect_error) {
    die(json_encode(["error" => "Error de conexión: " . $conn->connect_error]));
}

// Criterio: incidencia cuya fecha es menor a NOW()-INTERVAL 7 DAY, y estatus ≠ 'Cerrado con factura'
$sql = "SELECT id, numero_incidente, cliente, sucursal, estatus, fecha
        FROM incidencias
        WHERE estatus <> 'Cerrado con factura'
          AND fecha < (NOW() - INTERVAL 7 DAY)
        ORDER BY fecha ASC";

$result = $conn->query($sql);

$alertas = [];
if ($result && $result->num_rows > 0) {
    while($row = $result->fetch_assoc()) {
        $alertas[] = $row;
    }
}

echo json_encode(["alertas" => $alertas]);

$conn->close();
?>
