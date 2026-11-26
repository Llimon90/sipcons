<?php
// Alta de usuarios de la empresa. El correo es el usuario para iniciar sesión
// (único en toda la plataforma, ver config/usuarios.php).
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../auth/audit.php';
require_once __DIR__ . '/../auth/permisos.php';
require_once __DIR__ . '/../config/usuarios.php';
requirePermiso('usuarios');
header('Content-Type: application/json');

function responder(bool $ok, string $mensaje = ''): void {
    echo json_encode(['success' => $ok] + ($mensaje !== '' ? ['message' => $mensaje] : []), JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $nombre   = trim($_POST['nombre'] ?? '');
    $correo   = normalizarCorreo((string)($_POST['correo'] ?? ''));
    $telefono = trim($_POST['telefono'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    $rol      = $_POST['rol'] ?? '';

    if ($nombre === '' || $rol === '' || $password === '') responder(false, 'Nombre, correo, contraseña y rol son obligatorios.');
    if (mb_strlen($nombre) > 100) responder(false, 'El nombre es demasiado largo.');
    if ($error = errorCorreoUsuario($correo)) responder(false, $error);
    if (mb_strlen($telefono) > 20) responder(false, 'El teléfono es demasiado largo.');
    if (strlen($password) < MIN_PASSWORD_USUARIO) responder(false, 'La contraseña debe tener al menos ' . MIN_PASSWORD_USUARIO . ' caracteres.');
    if (!in_array($rol, ROLES_ASIGNABLES, true)) responder(false, 'Rol no válido.');
    if ($error = errorGestionCuenta(null, $rol)) responder(false, $error);
    if (!correoDisponible($pdo, $correo)) responder(false, MENSAJE_CORREO_OCUPADO);
    // AS-7: el nombre identifica al técnico en las órdenes: sin "/" y único en la empresa
    require_once __DIR__ . '/lib/tecnicos_helpers.php';
    if ($errorNombre = errorNombreUsuario($pdo, $nombre, (int)$_SESSION['empresa_id'])) responder(false, $errorNombre);

    // Límite de usuarios del plan contratado
    $empresaId = (int)$_SESSION['empresa_id'];
    require_once __DIR__ . '/../config/plataforma.php';
    // S-3: contar e insertar con la empresa bloqueada, para que dos altas simultáneas no rebasen el límite
    $pdo->beginTransaction();
    $pdo->prepare('SELECT id FROM empresas WHERE id = ? FOR UPDATE')->execute([$empresaId]);
    $limite = limiteUsuariosEmpresa($pdo, $empresaId);
    if ($limite !== null) {
        $stmtTotal = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE empresa_id = ?");
        $stmtTotal->execute([$empresaId]);
        if ((int)$stmtTotal->fetchColumn() >= $limite) {
            $pdo->rollBack();
            if (solidSatelite()) {
                responder(false, "Tu plan permite hasta $limite usuarios. Para agregar más, pide ampliarlo." . contactoComercial());
            }
            $planNombre = planPorId($pdo, (int)(empresaActual()['plan_id'] ?? 0))['nombre'] ?? 'actual';
            responder(false, "Tu plan $planNombre permite hasta $limite usuarios. Para agregar más, cambia a un plan superior." . contactoComercial());
        }
    }

    $stmt = $pdo->prepare("INSERT INTO usuarios (empresa_id, nombre, correo, telefono, usuario, password, rol) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$empresaId, $nombre, $correo, $telefono, $correo, password_hash($password, PASSWORD_DEFAULT), $rol]);
    $nuevoId = (int)$pdo->lastInsertId();
    $pdo->commit();

    // Nunca se guarda la contraseña (ni en texto plano ni el hash) en el historial
    registrarAuditoria('usuarios', $nuevoId, 'CREATE', null, [
        'nombre' => $nombre, 'correo' => $correo, 'telefono' => $telefono, 'usuario' => $correo, 'rol' => $rol,
    ]);
    responder(true);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    // Dos altas simultáneas con el mismo correo: la llave única de usuarios lo frena
    if ($e->getCode() === '23000') responder(false, MENSAJE_CORREO_OCUPADO);
    error_log('Alta de usuario: ' . $e->getMessage());
    responder(false, 'No se pudo registrar el usuario. Inténtalo de nuevo.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Alta de usuario: ' . $e->getMessage());
    responder(false, 'No se pudo registrar el usuario. Inténtalo de nuevo.');
}
