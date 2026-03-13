<?php
header('Content-Type: application/json');
ini_set('display_errors', 0);
error_reporting(E_ALL);

// ==============================================
// 1. Conexión a la base de datos
// ==============================================
require_once 'conexion.php';

// ==============================================
// 2. Detección Inteligente de Payload (JSON vs POST)
// ==============================================
$es_json = (isset($_SERVER["CONTENT_TYPE"]) && strpos($_SERVER["CONTENT_TYPE"], "application/json") !== false);

if ($es_json) {
    // Si la petición viene de Ventas (Fetch con JSON)
    $data = json_decode(file_get_contents('php://input'), true);
} else {
    // Si la petición viene de Incidencias (Form POST)
    $data = $_POST;
}

// ==============================================
// 3. Enrutamiento del Módulo e Identificación
// ==============================================
$modulo = null;
$idTabla = null;
$rutaFisica = null;

// Lógica universal: Detectar de qué módulo viene
if (!empty($data['modulo']) && $data['modulo'] === 'ventas') {
    // MÓDULO DE VENTAS
    $modulo = 'ventas';
    $idTabla = filter_var($data['id'], FILTER_VALIDATE_INT);
    $rutaArchivo = filter_var($data['ruta'], FILTER_SANITIZE_STRING);
    
    if (!$idTabla || !$rutaArchivo) {
        http_response_code(400);
        die(json_encode(['success' => false, 'error' => 'Datos incompletos de venta.']));
    }
} 
// Lógica para incidencias (compatibilidad con estructura existente)
else if (!empty($data['id_incidencia']) && !empty($data['url_archivo'])) {
    // MÓDULO DE INCIDENCIAS
    $modulo = 'incidencias';
    $idReferencia = filter_var($data['id_incidencia'], FILTER_VALIDATE_INT);
    $rutaArchivo = filter_var($data['url_archivo'], FILTER_SANITIZE_STRING);
    $nombreArchivo = basename($rutaArchivo);
    
    if (!$idReferencia || !$rutaArchivo) {
        http_response_code(400);
        die(json_encode(['success' => false, 'error' => 'Datos incompletos de incidencia.']));
    }
} else {
    // No se pudo identificar el módulo
    http_response_code(400);
    die(json_encode(['success' => false, 'error' => 'Parámetros no reconocidos por el servidor universal.']));
}

// ==============================================
// 4. Iniciar transacción
// ==============================================
$pdo->beginTransaction();

try {
    // ==============================================
    // 5. Eliminar registro de la BD según el módulo
    // ==============================================
    if ($modulo === 'incidencias') {
        $sql = "DELETE FROM archivos_incidencias WHERE incidencia_id = ? AND ruta_archivo LIKE ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$idReferencia, '%' . $nombreArchivo]);
        
        // Lógica de incidencias: buscar en apptest/uploads
        $rutaCompleta = $_SERVER['DOCUMENT_ROOT'] . '/apptest/uploads/' . $nombreArchivo;
    } 
    else if ($modulo === 'ventas') {
        // Lógica de ventas: eliminar directamente por ID único
        $sql = "DELETE FROM venta_archivos WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$idTabla]);
        
        // En ventas la ruta ya viene construida (ej: ../uploads/ventas/Cliente/archivo.pdf)
        $rutaCompleta = $rutaArchivo;
    }

    $deletedFromDb = $stmt->rowCount() > 0;
    
    if (!$deletedFromDb) {
        $pdo->rollBack();
        http_response_code(404);
        die(json_encode([
            'success' => false,
            'error' => 'Registro no encontrado en la base de datos'
        ]));
    }

    // ==============================================
    // 6. Eliminar archivo físico
    // ==============================================
    if (!file_exists($rutaCompleta)) {
        // Para mantener tu lógica estricta, si no hay archivo físico, cancelamos el borrado de la DB
        $pdo->rollBack();
        http_response_code(404);
        die(json_encode([
            'success' => false,
            'searched_path' => $rutaCompleta,
            'error' => 'Archivo no encontrado en el servidor físico'
        ]));
    }

    if (!unlink($rutaCompleta)) {
        $pdo->rollBack();
        http_response_code(500);
        die(json_encode([
            'success' => false,
            'searched_path' => $rutaCompleta,
            'error' => 'Error al eliminar archivo físico (Permisos denegados)'
        ]));
    }

    // ==============================================
    // 7. Confirmar transacción
    // ==============================================
    $pdo->commit();

    echo json_encode([
        'success' => true,
        'exito' => true, // Doble bandera para compatibilidad
        'message' => 'Archivo eliminado completamente del módulo ' . $modulo
    ]);
    
} catch (PDOException $e) {
    $pdo->rollBack();
    error_log("Error BD universal: " . $e->getMessage());
    http_response_code(500);
    die(json_encode([
        'success' => false,
        'error' => 'Error en base de datos',
        'debug' => ['message' => $e->getMessage()]
    ]));
} catch (Exception $e) {
    $pdo->rollBack();
    error_log("Error universal general: " . $e->getMessage());
    http_response_code(500);
    die(json_encode([
        'success' => false,
        'error' => 'Error interno',
        'message' => $e->getMessage()
    ]));
}
?>