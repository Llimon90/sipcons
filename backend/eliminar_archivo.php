<?php
header('Content-Type: application/json');

// 1. Configuración de conexión a la base de datos
$host = "localhost";
$user = "sipcons1_appweb";
$password = "sip*SYS2025";
$database = "sipcons1_appweb";

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
    // --- LOGGING PARA DEPURACIÓN ---
    error_log("ID de Incidencia recibido: " . $idIncidencia);
    error_log("URL de Archivo recibida: " . $urlArchivo);
    error_log("DOCUMENT_ROOT: " . $_SERVER['DOCUMENT_ROOT']);
    $rutaBase = $_SERVER['DOCUMENT_ROOT'] . '/uploads/';
    error_log("Ruta Base construida: " . $rutaBase);
    $rutaCompleta = realpath($rutaBase . ltrim($urlArchivo, '/'));
    error_log("Ruta Completa construida (realpath): " . $rutaCompleta);
    error_log("¿Existe el archivo?: " . (file_exists($rutaCompleta) ? 'Sí' : 'No'));
    // ---