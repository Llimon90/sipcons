<?php
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../auth/permisos.php';
requirePermisoAlguno('soporte');
// backend/descargar_documento.php - VERSIÓN COMPLETA

// Incluir conexión para verificar permisos

try {
    // Obtener parámetros
    $archivo = $_GET['archivo'] ?? '';
    $download = $_GET['download'] ?? false;
    
    if (empty($archivo)) {
        http_response_code(400);
        die('Archivo no especificado');
    }

    // El archivo debe estar realmente dentro de manuales/: realpath resuelve
    // cualquier "../" antes de comparar (evita salir de la carpeta)
    // y en concreto dentro de la carpeta de la empresa del usuario (manuales/e7)
    $baseManuales = realpath(__DIR__ . '/../' . carpetaEmpresa('manuales'));
    $ruta_completa = realpath(__DIR__ . '/../' . $archivo);
    if (strpos($archivo, carpetaEmpresa('manuales') . '/') !== 0 || !$baseManuales || !$ruta_completa
        || strpos($ruta_completa, $baseManuales . DIRECTORY_SEPARATOR) !== 0) {
        http_response_code(404);
        die('Archivo no encontrado');
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
    die('Error del servidor: ' . mensajeErrorPublico($e));
}
?>