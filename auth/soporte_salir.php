<?php
// Sale del modo soporte: el superadministrador vuelve a su panel de plataforma.
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/permisos.php';
require_once __DIR__ . '/../config/plataforma.php';

if (!empty($_SESSION['soporte'])) {
    terminarSoporte($pdo);
}
header('Location: ' . (esSuperadmin() ? '../plataforma/index.html' : 'login.html'));
exit;
