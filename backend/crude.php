<?php
// Configurar conexión con la base de datos
$host = "localhost";
$user = "u179371012_root";
$password = "Llimon.2025";
$database = "u179371012_sipcons";

$conn = new mysqli($host, $user, $password, $database);

// Verificar la conexión
if ($conn->connect_error) {
    die(json_encode(["error" => "Error de conexión: " . $conn->connect_error]));
}

// Permitir solicitudes desde el frontend
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

// Leer el método HTTP
$method = $_SERVER["REQUEST_METHOD"];

if ($method === "GET") {


   
    // **INICIO - FUNCIÓN PARA CREAR NUEVAS INCIDENCIAS**
    
    // Leer los datos enviados desde fetch()
    $data = json_decode(file_get_contents("php://input"), true);


    // Insertar la nueva incidencia
    $sql = "UPDATE incidencias SET estatus = 'facturada' WHERE id=183";    
    $stmt = $conn->prepare($sql);

    if ($stmt->execute()) {
        echo json_encode(["message" => "Incidencia actualizada correctamente", "numero_incidente" => $nuevoNumeroIncidente, "id" => $stmt->insert_id]);
    } else {
        echo json_encode(["error" => "Error al actualizar incidencia"]);
    }

    $stmt->close();
    
    
}

$conn->close();

?>