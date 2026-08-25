<?php
require_once __DIR__ . '/../config/auth.php';

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

session_destroy();

// Ruta relativa (sin "/" inicial): se resuelve contra la carpeta actual
// (auth/), donde vive login.html. Usar una ruta absoluta desde la raíz del
// dominio rompía el redirect cuando el sitio está desplegado en una
// subcarpeta (p. ej. /apptest), como ocurre en el entorno de pruebas.
header('Location: login.html');
exit;
