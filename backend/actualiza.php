<?php
require_once 'conexion.php';

if ($conn->connect_error) {
    die(json_encode(["error" => "Error de conexión: " . $conn->connect_error]));
}

// Leer los datos enviados desde el frontend
$id = $_POST['id'];
$numero = $_POST['numero'];
$cliente = $_POST['cliente'];
$contacto = $_POST['contacto'];
$sucursal = $_POST['sucursal'];
$equipo = $_POST['equipo']; // NUEVO CAMPO - valores: "Mr. Tienda/Mr. Chef" o "Otros"
$fecha = $_POST['fecha'];
$tecnico = $_POST['tecnico'];
$estatus = $_POST['estatus'];
$falla = $_POST['falla'];
$accion = $_POST['accion'];
$notas = $_POST['notas'];

// Debug: Verificar el valor de equipo
error_log("Valor de equipo recibido: " . $equipo);

// Actualizar la incidencia en la base de datos
$sql = "UPDATE incidencias SET 
        numero = ?, 
        cliente = ?, 
        contacto = ?, 
        sucursal = ?, 
        equipo = ?,  -- NUEVO CAMPO
        fecha = ?, 
        tecnico = ?, 
        estatus = ?, 
        falla = ?, 
        accion = ?, 
        notas = ? 
        WHERE id = ?";
        
$stmt = $conn->prepare($sql);
$stmt->bind_param("sssssssssssi", 
    $numero, 
    $cliente, 
    $contacto, 
    $sucursal, 
    $equipo,  // NUEVO CAMPO
    $fecha, 
    $tecnico,
    $estatus, 
    $falla, 
    $accion, 
    $notas, 
    $id
);

if ($stmt->execute()) {
    // Manejar la subida de archivos
    if (!empty($_FILES['archivos'])) {
        $uploadDir = '../uploads/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        foreach ($_FILES['archivos']['tmp_name'] as $key => $tmp_name) {
            $fileName = basename($_FILES['archivos']['name'][$key]);
            $uploadFilePath = $uploadDir . $fileName;

            if (move_uploaded_file($tmp_name, $uploadFilePath)) {
                $sql = "INSERT INTO archivos_incidencias (incidencia_id, ruta_archivo) VALUES (?, ?)";
                $stmt2 = $conn->prepare($sql);
                $stmt2->bind_param("is", $id, $uploadFilePath);
                $stmt2->execute();
                $stmt2->close();
            }
        }
    }

    echo json_encode(["success" => true]);
} else {
    echo json_encode(["error" => "Error al actualizar la incidencia: " . $stmt->error]);
}

$stmt->close();
$conn->close();
?>