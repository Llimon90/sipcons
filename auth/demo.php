<?php
// Datos de la página de la demo (/demo → auth/login.html?demo): credenciales
// públicas de la empresa demo (solo con DEMO_MODE=true) y si el registro de
// pruebas está abierto. El login de clientes no lo consulta.
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/demo.php';
require_once __DIR__ . '/../config/plataforma.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

echo json_encode([
    'demo'     => demoCredencialesPublicas(),
    'registro' => registroAbierto(),
    // Contacto de soporte para "¿Olvidaste tu contraseña?" (panel de plataforma > Configuración)
    'soporte'  => ['whatsapp' => configPlataforma('contacto_whatsapp'), 'correo' => configPlataforma('contacto_correo')],
], JSON_UNESCAPED_UNICODE);
