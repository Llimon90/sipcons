<?php
include 'conexion.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $marca_id = $_POST['marca'];
    $modelo_nombre = $_POST['modelo_nombre'];
    $modelo_tipo = $_POST['modelo_tipo'];
    
    try {
        // Insertar el modelo en la base de datos
        $stmt = $conn->prepare("INSERT INTO modelos (marca_id, nombre, tipo) VALUES (?, ?, ?)");
        $stmt->execute([$marca_id, $modelo_nombre, $modelo_tipo]);
        $modelo_id = $conn->lastInsertId();
        
        // Crear la estructura de carpetas
        $marca_nombre = obtenerNombreMarca($conn, $marca_id);
        $base_path = "../manuales/" . $marca_nombre . "/" . $modelo_nombre . "/";
        
        // Crear carpetas si no existen
        if (!file_exists($base_path)) {
            mkdir($base_path, 0777, true);
        }
        
        // Procesar archivos subidos
        procesarArchivo($_FILES['manual_file'], $base_path, 'manual', $modelo_id, $conn);
        procesarArchivo($_FILES['partes_file'], $base_path, 'partes', $modelo_id, $conn);
        
        echo json_encode(['success' => true, 'message' => 'Modelo agregado correctamente']);
        
    } catch(PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
}

function obtenerNombreMarca($conn, $marca_id) {
    $stmt = $conn->prepare("SELECT nombre FROM marcas WHERE id = ?");
    $stmt->execute([$marca_id]);
    return $stmt->fetchColumn();
}

function procesarArchivo($archivo, $base_path, $tipo, $modelo_id, $conn) {
    if ($archivo['error'] === UPLOAD_ERR_OK) {
        $nombre_archivo = basename($archivo['name']);
        $ruta_completa = $base_path . $nombre_archivo;
        
        if (move_uploaded_file($archivo['tmp_name'], $ruta_completa)) {
            // Guardar en la base de datos
            $stmt = $conn->prepare("INSERT INTO documentos (modelo_id, tipo, nombre, archivo) VALUES (?, ?, ?, ?)");
            $nombre_documento = ($tipo == 'manual') ? 'Manual de Usuario' : 'Lista de Números de Parte';
            $stmt->execute([$modelo_id, $tipo, $nombre_documento, $nombre_archivo]);
        }
    }
}
?>