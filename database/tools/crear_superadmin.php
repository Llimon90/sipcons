<?php
// Crea (o restablece la contraseña de) la cuenta de superadministrador de la
// plataforma: el usuario sin empresa que administra empresas, pruebas y pagos.
//
//   php database/tools/crear_superadmin.php --correo=tu@correo.com
//
// Sin acceso por consola al servidor, genera el SQL y pégalo en phpMyAdmin:
//   php database/tools/crear_superadmin.php --correo=tu@correo.com --solo-sql
//
// La contraseña se pide en pantalla: así la terminal (PowerShell, cmd) no
// altera caracteres como $ ! % ^ ". Volver a ejecutarlo con el mismo correo
// restablece la contraseña.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$o = getopt('', ['correo:', 'password:', 'nombre::', 'solo-sql']);
$correo = strtolower(trim($o['correo'] ?? ''));
if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Uso: php database/tools/crear_superadmin.php --correo=tu@correo.com [--solo-sql] [--nombre=\"...\"]\n");
    exit(1);
}

$password = (string)($o['password'] ?? '');
if ($password === '') {
    fwrite(STDOUT, "Contraseña para $correo (10+ caracteres): ");
    $password = rtrim((string)fgets(STDIN), "\r\n");
    fwrite(STDOUT, "Repítela: ");
    if (rtrim((string)fgets(STDIN), "\r\n") !== $password) {
        fwrite(STDERR, "\nLas contraseñas no coinciden.\n");
        exit(1);
    }
}
if (strlen($password) < 10) {
    fwrite(STDERR, "\nLa contraseña debe tener al menos 10 caracteres.\n");
    exit(1);
}

$nombre = $o['nombre'] ?? 'Administrador de plataforma';
$hash = password_hash($password, PASSWORD_DEFAULT);

if (isset($o['solo-sql'])) {
    // Si el correo ya es la cuenta de plataforma, actualiza su contraseña; si
    // pertenece a un usuario de una empresa, no cambia nada (ni lo convierte).
    $q = fn($v) => "'" . str_replace("'", "''", $v) . "'";
    echo "\n\n-- Pega esto en phpMyAdmin > SQL:\n"
       . "INSERT INTO usuarios (empresa_id, nombre, correo, telefono, usuario, password, rol) VALUES\n"
       . "(NULL, {$q($nombre)}, {$q($correo)}, '', {$q($correo)}, {$q($hash)}, 'Superadmin')\n"
       . "ON DUPLICATE KEY UPDATE password = IF(rol = 'Superadmin' AND empresa_id IS NULL, VALUES(password), password);\n";
    exit(0);
}

require_once __DIR__ . '/../../config/database.php';

$stmt = $pdo->prepare("SELECT id, empresa_id, rol FROM usuarios WHERE usuario = ?");
$stmt->execute([$correo]);
$existente = $stmt->fetch(PDO::FETCH_ASSOC);

if ($existente && ($existente['empresa_id'] !== null || $existente['rol'] !== 'Superadmin')) {
    // Nunca convertir en superadministrador a un usuario de una empresa
    fwrite(STDERR, "Error: $correo ya es un usuario de una empresa. Usa otro correo para la cuenta de plataforma.\n");
    exit(1);
}

if ($existente) {
    $pdo->prepare("UPDATE usuarios SET password = ? WHERE id = ?")->execute([$hash, $existente['id']]);
    echo "\nContraseña del superadministrador actualizada: $correo\n";
} else {
    $pdo->prepare(
        "INSERT INTO usuarios (empresa_id, nombre, correo, telefono, usuario, password, rol)
         VALUES (NULL, ?, ?, '', ?, ?, 'Superadmin')"
    )->execute([$nombre, $correo, $correo, $hash]);
    echo "\nSuperadministrador creado: $correo\n";
}
