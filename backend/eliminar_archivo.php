<?php
header('Content-Type: application/json');

// 1. Configuración de conexión a la base de datos
$host = "localhost";
$user = "u179371012_root";
$password = "Llimon.2025";
$database = "u179371012_sipcons";

try {
    $pdo = new PDO("mysql:host=$host;dbname=$database;charset=utf8", $user, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Error de conexión a la base de datos',
        'details' => $e->getMessage()
    ]);
    exit;
}

// 2. Obtener y validar datos del POST
$idIncidencia = isset($_POST['id_incidencia']) ? (int)$_POST['id_incidencia'] : null;
$urlArchivo = isset($_POST['url_archivo']) ? $_POST['url_archivo'] : null;

if (!$idIncidencia || !$urlArchivo) {
    echo json_encode([
        'success' => false,
        'error' => 'Datos incompletos',
        'received' => [
            'id_incidencia' => $idIncidencia,
            'url_archivo' => $urlArchivo
        ]
    ]);
    exit;
}

try {
    // 3. Eliminar registro de la base de datos
    $stmt = $pdo->prepare("DELETE FROM archivos_incidnecias WHERE incidencia_id = :id AND ruta_archivo = :ruta");
    $stmt->bindParam(':id', $idIncidencia, PDO::PARAM_INT);
    $stmt->bindParam(':ruta', $urlArchivo, PDO::PARAM_STR);
    $stmt->execute();

    if ($stmt->rowCount() === 0) {
        throw new Exception('No se encontró el registro en la base de datos');
    }

    // 4. Eliminar archivo físico (si existe)
    $rutaBase = $_SERVER['DOCUMENT_ROOT'] . '/uploads/';
    $rutaRelativa = ltrim(parse_url($urlArchivo, PHP_URL_PATH), '/');
    $rutaCompleta = realpath($rutaBase . $rutaRelativa);

    if ($rutaCompleta && strpos($rutaCompleta, $rutaBase) === 0) {
        if (file_exists($rutaCompleta) && !unlink($rutaCompleta)) {
            throw new Exception('Archivo eliminado de la BD pero no del servidor');
        }
    }

    // 5. Obtener archivos restantes (opcional)
    $stmt = $pdo->prepare("SELECT ruta_archivo FROM archivos_incidnecias WHERE incidencia_id = :id");
    $stmt->bindParam(':id', $idIncidencia, PDO::PARAM_INT);
    $stmt->execute();
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
            'ruta_base' => $rutaBase ?? null,
            'ruta_relativa' => $rutaRelativa ?? null,
            'ruta_completa' => $rutaCompleta ?? null
        ]
    ]);
}
?>