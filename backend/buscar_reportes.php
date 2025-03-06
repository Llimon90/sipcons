<?php
// Configurar conexión con la base de datos
$host = "localhost";
$user = "u179371012_root";
$password = "Llimon.2025";
$database = "u179371012_sipcons";

// Crear conexión
$conn = new mysqli($host, $user, $password, $database);

// Verificar conexión
if ($conn->connect_error) {
    die(json_encode(["error" => "Error de conexión: " . $conn->connect_error]));
}

// ** INICIO - FUNCIÓN PARA MOSTRAR LA BASE DE DATOS EN EL DOM **

// Consulta para obtener todas las incidencias sin filtrar
$sql = "SELECT * FROM incidencias";
$result = $conn->query($sql);

if ($result->num_rows > 0) {
    $incidencias = [];
    while($row = $result->fetch_assoc()) {
        $incidencias[] = $row;
    }
    echo json_encode($incidencias);
} else {
    echo json_encode(["message" => "No hay incidencias abiertas"]);
}

// ** FIN - FUNCIÓN PARA MOSTRAR LA BASE DE DATOS EN EL DOM **

// Cerrar la conexión
$conn->close();
?>
