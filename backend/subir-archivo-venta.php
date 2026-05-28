<?php
// Configuración
$uploadDir = __DIR__ . '/../uploads/ventas/';
$allowedExtensions = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp'];

// Crear directorio si no existe
if (!file_exists($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

// Procesar archivos
$response = ['exito' => false, 'mensaje' => '', 'archivos' => []];

if (isset($_FILES['archivos'])) {
    foreach ($_FILES['archivos']['tmp_name'] as $key => $tmpName) {
        $fileName = $_FILES['archivos']['name'][$key];
        $fileSize = $_FILES['archivos']['size'][$key];
        $fileTmp = $_FILES['archivos']['tmp_name'][$key];
        $fileType = $_FILES['archivos']['type'][$key];
        $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        // Validar extensión
        if (!in_array($fileExt, $allowedExtensions)) {
            $response['mensaje'] = "Extensión no permitida: $fileExt";
            echo json_encode($response);
            exit;
        }

        // Generar nombre único
        $newFileName = uniqid() . '.' . $fileExt;
        $uploadPath = $uploadDir . $newFileName;

        // Mover archivo
        if (move_uploaded_file($fileTmp, $uploadPath)) {
            $response['archivos'][] = [
                'nombre' => $newFileName,
                'nombre_original' => $fileName,
                'ruta' => '/uploads/ventas/' . $newFileName
            ];
            $response['exito'] = true;
        } else {
            $response['mensaje'] = "Error al subir el archivo $fileName";
        }
    }
} else {
    $response['mensaje'] = 'No se recibieron archivos';
}

echo json_encode($response);
?>