<?php
header('Content-Type: application/json');
ini_set('display_errors', 0);
error_reporting(E_ALL);

// ==============================================
// 1. Conexión a la base de datos
// ==============================================
require_once 'conexion.php';

// ==============================================
// 2. Validación de datos POST
// ==============================================
$idIncidencia = filter_input(INPUT_POST, 'id_incidencia', FILTER_VALIDATE_INT);
$rutaArchivo = filter_input(INPUT_POST, 'url_archivo', FILTER_SANITIZE_STRING);
$nombreArchivo = basename($rutaArchivo);

if (!$idIncidencia || !$rutaArchivo) {
    http_response_code(400);
    die(json_encode([
        'success' => false,
        'error' => 'Datos incompletos o inválidos',
        'received' => $_POST
    ]));
}

// ==============================================
// 3. Iniciar transacción
// ==============================================
$pdo->beginTransaction();

try {
    // ==============================================
    // 4. Eliminar registro de la BD (ajustado a tu estructura)
    // ==============================================
    $sql = "DELETE FROM archivos_incidencias WHERE incidencia_id = ? AND ruta_archivo LIKE ?";
    $stmt = $pdo->prepare($sql);
    
    // Buscar tanto por ruta completa como solo por nombre de archivo
    $stmt->execute([$idIncidencia, '%'.$nombreArchivo]);
    
    $deletedFromDb = $stmt->rowCount() > 0;
    
    if (!$deletedFromDb) {
        $pdo->rollBack();
        http_response_code(404);
        die(json_encode([
            'success' => false,
            'error' => 'Registro no encontrado en la base de datos',
            'debug' => [
                'query' => $sql,
                'params' => [$idIncidencia, '%'.$nombreArchivo],
                'table_structure' => 'id, incidencia_id, ruta_archivo'
            ]
        ]));
    }

    // ==============================================
    // 5. Eliminar archivo físico
    // ==============================================
    $rutaBase = $_SERVER['DOCUMENT_ROOT'] . '/apptest/uploads/';
    $rutaCompleta = $rutaBase . $nombreArchivo;

    if (!file_exists($rutaCompleta)) {
        $pdo->rollBack();
        http_response_code(404);
        die(json_encode([
            'success' => false,
            'error' => 'Archivo no encontrado en el servidor',
            'searched_path' => $rutaCompleta
        ]));
    }

    if (!unlink($rutaCompleta)) {
        $pdo->rollBack();
        http_response_code(500);
        die(json_encode([
            'success' => false,
            'error' => 'Error al eliminar archivo físico',
            'file_permissions' => [
                'readable' => is_readable($rutaCompleta),
                'writable' => is_writable($rutaCompleta)
            ]
        ]));
    }

    // Confirmar transacción si todo fue exitoso
    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Archivo eliminado completamente',
        'details' => [
            'db_deleted' => true,
            'file_deleted' => true,
            'incidencia_id' => $idIncidencia,
            'archivo' => $nombreArchivo
        ]
    ]);
    
} catch (PDOException $e) {
    $pdo->rollBack();
    error_log("Error BD al eliminar archivo: " . $e->getMessage());
    http_response_code(500);
    die(json_encode([
        'success' => false,
        'error' => 'Error en base de datos',
        'debug' => [
            'message' => $e->getMessage(),
            'code' => $e->getCode(),
            'query' => $sql ?? 'No ejecutada'
        ]
    ]));
} catch (Exception $e) {
    $pdo->rollBack();
    error_log("Error general: " . $e->getMessage());
    http_response_code(500);
    die(json_encode([
        'success' => false,
        'error' => 'Error al procesar la solicitud',
        'exception' => get_class($e),
        'message' => $e->getMessage()
    ]));
}
?>