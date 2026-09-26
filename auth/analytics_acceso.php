<?php
// Control de acceso del panel de analíticas de uso. A propósito NO usa la
// tabla permisos_rol ni el checklist de Ajustes: es un chequeo fijo en código
// para que ningún rol (ni siquiera Administrador) pueda otorgárselo.
//
// Pueden verlo: el rol Programador y, opcionalmente, los usuarios listados en
// la variable ANALYTICS_USUARIOS del archivo .env (separados por comas).
require_once __DIR__ . '/permisos.php';

function puedeVerAnaliticas(): bool {
    if (!function_exists('isLoggedIn') || !isLoggedIn()) {
        return false;
    }
    if (($_SESSION['rol'] ?? '') === ROL_ACCESO_TOTAL) {
        return true;
    }
    $permitidos = array_filter(array_map('trim', explode(',', $_ENV['ANALYTICS_USUARIOS'] ?? '')));
    return !empty($permitidos) && in_array($_SESSION['usuario'] ?? '', $permitidos, true);
}

function requireAnaliticas(): void {
    if (!puedeVerAnaliticas()) {
        http_response_code(404);
        header('Content-Type: application/json');
        die(json_encode(['error' => 'No encontrado']));
    }
}
