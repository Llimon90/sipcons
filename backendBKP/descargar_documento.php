<?php
// backend/descargar_documento.php - VERSIÓN COMPLETA
header('Access-Control-Allow-Origin: *');

// Incluir conexión para verificar permisos
require_once 'conexion.php';

try {
    // Obtener parámetros
    $archivo = $_GET['archivo'] ?? '';
    $download = $_GET['download'] ?? false;
    
    if (empty($archivo)) {
        http_response_code(400);
        die('Archivo no especificado');
    }

    // Validar que el archivo esté dentro de la carpeta manuales
    if (strpos($archivo, 'manuales/') !== 0) {
        http_response_code(403);
        die('Acceso denegado');
    }

    // Ruta completa al archivo
    $ruta_completa = '../' . $archivo;
    
    // Verificar que el archivo existe
    if (!file_exists($ruta_completa)) {
        http_response_code(404);
        die('Archivo no encontrado: ' . $ruta_completa);
    }

    // Verificar que es un PDF
    $extension = strtolower(pathinfo($ruta_completa, PATHINFO_EXTENSION));
    if ($extension !== 'pdf') {
        http_response_code(403);
        die('Tipo de archivo no permitido');
    }

    // Obtener el nombre del archivo original desde la base de datos
    $db = new Database();
    $conn = $db->getConnection();
    
    $stmt = $conn->prepare("SELECT nombre_archivo FROM documentos WHERE ruta_archivo = ?");
    $stmt->execute([$archivo]);
    $documento = $stmt->fetch();
    
    $nombre_original = $documento ? $documento['nombre_archivo'] : basename($archivo);

    // Configurar headers según si es descarga o visualización
    header('Content-Type: application/pdf');
    header('Content-Length: ' . filesize($ruta_completa));
    header('Cache-Control: public, must-revalidate, max-age=0');
    header('Pragma: public');
    header('Expires: Sat, 26 Jul 1997 05:00:00 GMT');
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');

    if ($download) {
        // Forzar descarga
        header('Content-Disposition: attachment; filename="' . $nombre_original . '"');
    } else {
        // Visualizar en el navegador
        header('Content-Disposition: inline; filename="' . $nombre_original . '"');
    }

    // Limpiar buffer de salida y leer el archivo
    if (ob_get_level()) {
        ob_end_clean();
    }
    readfile($ruta_completa);
    exit;

} catch (Exception $e) {
    http_response_code(500);
    die('Error del servidor: ' . $e->getMessage());
}
?>