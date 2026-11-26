<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/permisos.php';
require_once __DIR__ . '/../config/contratacion.php';
require_once __DIR__ . '/../config/usuarios.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['authenticated' => false]);
    exit;
}

// Usuario eliminado, movido de empresa o con contraseña cambiada: se cierra la sesión
if (!revalidarUsuarioSesion()) {
    cerrarSesion();
    http_response_code(401);
    echo json_encode(['authenticated' => false, 'mensaje' => 'Tu sesión terminó porque tu cuenta cambió. Inicia sesión de nuevo.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Sesiones sin empresa: el superadministrador va a su panel; cualquier otra
// es anterior a la migración multiempresa y se cierra.
if (empty($_SESSION['empresa_id'])) {
    if (esSuperadmin()) {
        echo json_encode(['authenticated' => true, 'plataforma' => true, 'user' => currentUser()]);
        exit;
    }
    cerrarSesion();
    http_response_code(401);
    echo json_encode(['authenticated' => false]);
    exit;
}

// Empresa bloqueada (prueba vencida, suspendida...): se cierra la sesión y el
// login muestra el motivo
$empresa = empresaActual();
$situacion = situacionEmpresa($empresa);
if ($situacion['acceso'] === 'bloqueado' && empty($_SESSION['soporte'])) {
    cerrarSesion();
    http_response_code(403);
    echo json_encode(['authenticated' => false, 'mensaje' => $situacion['mensaje'], 'estado_empresa' => $situacion['estado']], JSON_UNESCAPED_UNICODE);
    exit;
}

// Tema (claro/oscuro) guardado en la cuenta; si la migración 012 aún no se
// aplicó, se ignora y el sistema sigue en modo claro.
$tema = 'claro';
try {
    $stmtTema = $pdo->prepare('SELECT tema FROM usuarios WHERE id = ? LIMIT 1');
    $stmtTema->execute([$_SESSION['user_id']]);
    $guardado = $stmtTema->fetchColumn();
    if ($guardado === 'oscuro') $tema = 'oscuro';
} catch (\Throwable $e) {
    error_log('No se pudo leer el tema del usuario (¿falta la migración 012?): ' . $e->getMessage());
}

echo json_encode([
    'authenticated' => true,
    'user'          => currentUser(),
    'modulos'       => modulosPermitidos(),
    'tema'          => $tema,
    'empresa'       => [
        'id'      => (int)$empresa['id'],
        'nombre'  => $empresa['nombre'],
        'estado'  => $situacion['estado'],
        'acceso'  => $situacion['acceso'],
        'dias'    => $situacion['dias'],
        'verificacion' => !empty($situacion['verificacion']),
        'mensaje' => $situacion['mensaje'],
        // Administrador que puede contratar o renovar (auth/contratar.html)
        'puede_contratar' => empty($_SESSION['soporte']) && puedeContratar($pdo),
        // Administra la cuenta: ve Mi empresa (plan, uso y marca)
        'administra' => rolPuedeContratar($_SESSION['rol'] ?? null),
    ],
    // Modo soporte: el superadministrador está dentro de esta empresa
    'soporte'       => !empty($_SESSION['soporte']),
    // Si puede poner estatus "Cerrado con/sin factura" (rol y permiso del usuario)
    'puede_cerrar'  => puedeCerrarIncidencias(),
    // L-13: contraseña temporal del panel pendiente de cambiar (auth-guard lleva a Ajustes)
    'debe_cambiar_password' => empty($_SESSION['soporte']) && usuarioDebeCambiarPassword($pdo, (int)$_SESSION['user_id']),
]);
