<?php
header_remove();
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type");

ini_set('display_errors', 1);
error_reporting(E_ALL);

function sendResponse($success, $message, $data = [], $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode(['exito'=>$success,'mensaje'=>$message,'data'=>$data,'timestamp'=>date('Y-m-d H:i:s')]);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendResponse(false, 'Método no permitido', [], 405);
    }

    $jsonInput = file_get_contents('php://input');
    $data = json_decode($jsonInput, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        sendResponse(false, 'JSON inválido: '.json_last_error_msg(), [], 400);
    }

    // Validar campos
    foreach (['cliente','equipo','garantia','numero_series'] as $campo) {
        if (empty($data[$campo]) || ($campo === 'numero_series' && !is_array($data[$campo]))) {
            sendResponse(false, "Falta o es inválido el campo: $campo", [], 400);
        }
    }

    require_once 'conexion.php';

    $sql = "INSERT INTO ventas (
                cliente, sucursal, equipo, marca, modelo, numero_serie,
                garantia, servicio, notas, fecha_registro
            ) VALUES (
                :cliente, :sucursal, :equipo, :marca, :modelo, :numero_serie,
                :garantia, :servicio, :notas, NOW()
            )";

    $stmt = $pdo->prepare($sql);
    $pdo->beginTransaction();

    foreach ($data['numero_series'] as $serie) {
        $stmt->execute([
            ':cliente'      => trim($data['cliente']),
            ':sucursal'     => trim($data['sucursal'] ?? ''),
            ':equipo'       => trim($data['equipo']),
            ':marca'        => trim($data['marca'] ?? ''),
            ':modelo'       => trim($data['modelo'] ?? ''),
            ':numero_serie' => trim($serie),
            ':garantia'     => (int)$data['garantia'],
            ':servicio'     => !empty($data['servicio']) ? 1 : 0,
            ':notas'        => trim($data['notas'] ?? '')
        ]);
    }

    $pdo->commit();
    sendResponse(true, 'Ventas registradas: '.count($data['numero_series']), ['insertados'=>count($data['numero_series'])]);

} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("Error PDO: ".$e->getMessage());
    sendResponse(false, 'Error en BD: '.$e->getMessage(), [], 500);
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log("Error general: ".$e->getMessage());
    sendResponse(false, 'Error del servidor', [], 500);
}
