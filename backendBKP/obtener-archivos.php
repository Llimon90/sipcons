<?php
// Configuración de la base de datos
$host = "localhost";
$user = "sipcons1_appweb";
$password = "sip*SYS2025";
$database = "sipcons1_appweb";

$conn = new mysqli($host, $user, $password, $database);

// Verificar la conexión
if ($conn->connect_error) {
    die(json_encode(["error" => "Error de conexión: " . $conn->connect_error]));
}

// Obtener el número de incidente desde la URL
if (!isset($_GET['numero_incidente'])) {
    die(json_encode(["error" => "Número de incidente no proporcionado"]));
}

$numero_incidente = $_GET['numero_incidente'];

// Consulta para obtener los archivos de la incidencia
$sql = "SELECT ruta_archivo FROM archivos_incidencias 
        INNER JOIN incidencias ON archivos_incidencias.incidencia_id = incidencias.id
        WHERE incidencias.numero = ?";

$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $numero_incidente);
$stmt->execute();
$result = $stmt->get_result();

$archivos = [];
while ($row = $result->fetch_assoc()) {
    $archivos[] = $row['ruta_archivo']; // Guardamos las rutas de los archivos
}

$stmt->close();
$conn->close();

echo json_encode($archivos);
?>
