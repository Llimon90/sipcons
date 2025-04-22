<?php
header('Content-Type: application/json');

// 1. Configuración de la base de datos
require_once 'conexion.php'; // Archivo con tus credenciales

// 2. Obtener datos del POST (FormData)
$idIncidencia = $_POST['id_incidencia'] ?? null;
$urlArchivo = $_POST['url_archivo'] ?? null;

// Validación básica
if (empty($idIncidencia) || empty($urlArchivo)) {
    echo json_encode([
        'success' => false,
        'error' => 'Datos incompletos: id_incidencia o url_archivo faltantes'
    ]);
    exit;
}

try {
    // 3. Obtener la ruta física del archivo
    $rutaBase = realpath($_SERVER['DOCUMENT_ROOT'] . '/uploads/') . '/';
    $rutaRelativa = str_replace(['../', '..\\'], '', urldecode(parse_url($urlArchivo, PHP_URL_PATH)));
    $rutaArchivo = realpath($rutaBase . ltrim($rutaRelativa, '/'));
    
    // Validación de seguridad
    if (!$rutaArchivo || strpos($rutaArchivo, $rutaBase) !== 0) {
        throw new Exception('Ruta de archivo no válida');
    }

    // 4. Eliminar el archivo físico (si existe)
    if (file_exists($rutaArchivo)) {
        if (!unlink($rutaArchivo)) {
            throw new Exception('No se pudo eliminar el archivo físico');
        }
    }

    // 5. Eliminar de la base de datos
    $stmt = $pdo->prepare("DELETE FROM archivos_incidencias WHERE incidencia_id = :id AND ruta_archivo = :ruta");
    $stmt->bindParam(':id', $idIncidencia, PDO::PARAM_INT);
    $stmt->bindParam(':ruta', $urlArchivo, PDO::PARAM_STR);
    $stmt->execute();

    if ($stmt->rowCount() === 0) {
        throw new Exception('No se encontró el registro en la base de datos');
    }

    // 6. Obtener archivos restantes
    $stmt = $pdo->prepare("SELECT ruta_archivo FROM archivos_incidencias WHERE incidencia_id = :id");
    $stmt->bindParam(':id', $idIncidencia, PDO::PARAM_INT);
    $stmt->execute();
    $archivos = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // 7. Respuesta exitosa
    echo json_encode([
        'success' => true,
        'message' => 'Archivo eliminado correctamente',
        'archivos' => $archivos
    ]);

} catch (Exception $e) {
    // 8. Manejo de errores
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>