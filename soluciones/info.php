<?php
// Datos públicos del sitio de ventas (soluciones/*.html): planes vigentes,
// días de prueba, si el registro está abierto y el contacto comercial. Todo
// sale del panel de plataforma; nada de aquí identifica a una empresa cliente.
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/demo.php';
require_once __DIR__ . '/../config/plataforma.php';

header('Content-Type: application/json');
header('Cache-Control: public, max-age=300');

$planes = [];
try {
    $planes = $pdo->query(
        "SELECT nombre, descripcion, precio_mensual, precio_anual, max_usuarios
           FROM planes WHERE activo = 1 ORDER BY orden, id"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('soluciones/info.php: ' . $e->getMessage());
}

$dias = (int)configPlataforma('dias_prueba');
echo json_encode([
    'planes'     => array_map(fn($p) => [
        'nombre'         => $p['nombre'],
        'descripcion'    => $p['descripcion'],
        'precio_mensual' => (float)$p['precio_mensual'],
        'precio_anual'   => (float)$p['precio_anual'],
        'max_usuarios'   => $p['max_usuarios'] !== null ? (int)$p['max_usuarios'] : null,
    ], $planes),
    'dias_prueba' => $dias > 0 ? $dias : 15,
    'registro'    => registroAbierto(),
    'demo'        => demoCredencialesPublicas() !== null,
    'whatsapp'    => preg_replace('/\D/', '', (string)configPlataforma('contacto_whatsapp')),
    'correo'      => (string)configPlataforma('contacto_correo'),
    'aviso'       => (string)env('APP_AVISO_PRIVACIDAD_URL', ''),
], JSON_UNESCAPED_UNICODE);
