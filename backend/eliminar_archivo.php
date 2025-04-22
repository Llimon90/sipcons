<?php
header('Content-Type: application/json');

// Configuración de la base de datos
require_once 'conexion.php'; // Asegúrate de tener este archivo con tus credenciales

// Obtener datos del POST
$idIncidencia = $_POST['id_incidencia'] ?? null;
$urlArchivo = $_POST['url_archivo'] ?? null;

if (!$idIncidencia || !$urlArchivo) {
    echo json_encode(['success' => false, 'error' => 'Datos incompletos']);
    exit;
}

try {
    // 1. Eliminar el archivo físico del servidor
    $rutaArchivo = realpath($_SERVER['DOCUMENT_ROOT'] . parse_url($urlArchivo, PHP_URL_PATH));
    
    if (file_exists($rutaArchivo)) {
        if (!unlink($rutaArchivo)) {
            throw new Exception('No se pudo eliminar el archivo físico');
        }
    }

    // 2. Eliminar la referencia de la base de datos
    // Asumiendo que tienes una tabla 'archivos_incidencias' con estructura similar a:
    // id | incidencia_id | ruta_archivo | nombre_archivo | fecha_creacion
    
    $stmt = $pdo->prepare("DELETE FROM archivos_incidencias WHERE incidencia_id = ? AND ruta_archivo = ?");
    $stmt->execute([$idIncidencia, $urlArchivo]);

    if ($stmt->rowCount() === 0) {
        throw new Exception('No se encontró el registro en la base de datos');
    }

    // 3. Obtener la lista actualizada de archivos
    $stmt = $pdo->prepare("SELECT ruta_archivo FROM archivos_incidencias WHERE incidencia_id = ?");
    $stmt->execute([$idIncidencia]);
    $archivos = $stmt->fetchAll(PDO::FETCH_COLUMN);

    echo json_encode([
        'success' => true,
        'archivos' => $archivos
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>