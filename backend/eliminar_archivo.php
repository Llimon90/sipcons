<?php
header('Content-Type: application/json');

// 1. Configuración de la base de datos
require_once 'conexion.php';

// 2. Obtener datos del POST
$idIncidencia = $_POST['id_incidencia'] ?? null;
$urlArchivo = $_POST['url_archivo'] ?? null;

if (empty($idIncidencia) || empty($urlArchivo)) {
    echo json_encode(['success' => false, 'error' => 'Datos incompletos']);
    exit;
}

try {
    // 3. Obtener la ruta base de uploads (ajusta esta ruta según tu estructura)
    $rutaBaseUploads = realpath($_SERVER['DOCUMENT_ROOT'] . '/uploads/') . '/';
    
    // 4. Extraer la parte relativa de la URL (manejo seguro)
    $urlParts = parse_url($urlArchivo);
    $rutaRelativa = ltrim($urlParts['path'], '/');
    $rutaRelativa = str_replace(['../', '..\\'], '', $rutaRelativa); // Prevenir directory traversal
    
    // 5. Construir ruta completa del archivo
    $rutaCompleta = realpath($rutaBaseUploads . $rutaRelativa);
    
    // 6. Validaciones de seguridad
    if ($rutaCompleta === false) {
        throw new Exception('El archivo no existe en el servidor');
    }
    
    if (strpos($rutaCompleta, $rutaBaseUploads) !== 0) {
        throw new Exception('Intento de acceso a ruta no permitida');
    }

    // 7. Eliminar el archivo físico
    if (!unlink($rutaCompleta)) {
        throw new Exception('No se pudo eliminar el archivo físico');
    }

    // 8. Eliminar de la base de datos
    $stmt = $pdo->prepare("DELETE FROM archivos_incidencias WHERE incidencia_id = ? AND ruta_archivo = ?");
    $stmt->execute([$idIncidencia, $urlArchivo]);

    if ($stmt->rowCount() === 0) {
        throw new Exception('No se encontró el registro en la base de datos');
    }

    // 9. Obtener lista actualizada de archivos
    $stmt = $pdo->prepare("SELECT ruta_archivo FROM archivos_incidencias WHERE incidencia_id = ?");
    $stmt->execute([$idIncidencia]);
    $archivos = $stmt->fetchAll(PDO::FETCH_COLUMN);

    echo json_encode([
        'success' => true,
        'message' => 'Archivo eliminado correctamente',
        'archivos' => $archivos
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'debug' => [
            'ruta_base' => $rutaBaseUploads ?? null,
            'ruta_relativa' => $rutaRelativa ?? null,
            'ruta_completa' => $rutaCompleta ?? null
        ]
    ]);
}
?>