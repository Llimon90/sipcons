<?php
$host = "localhost";
$user = "u179371012_root";
$password = "Llimon.2025";
$database = "u179371012_sipcons";

// Crear conexión
$conn = new mysqli($servername, $username, $password, $dbname);

// Verificar conexión
if ($conn->connect_error) {
    die("Error de conexión: " . $conn->connect_error);
}

// Verificar si se recibieron datos
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $nombre = $_POST['name'];
    $rfc = $_POST['tax-info'];
    $direccion = $_POST['address'];
    $telefono = $_POST['phone'];
    $contactos = $_POST['contacts'];
    $email = $_POST['email'];

    // Preparar y vincular
    $stmt = $conn->prepare("INSERT INTO clientes (nombre, rfc, direccion, telefono, contactos, email) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("ssssss", $nombre, $rfc, $direccion, $telefono, $contactos, $email);

    // Ejecutar la consulta
    if ($stmt->execute()) {
        echo json_encode(["message" => "Cliente registrado con éxito."]);
    } else {
        echo json_encode(["error" => "Error al registrar el cliente: " . $stmt->error]);
    }

    // Cerrar la declaración
    $stmt->close();
}

// Cerrar conexión
$conn->close();
?>
