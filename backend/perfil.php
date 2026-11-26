<?php
// Perfil del usuario (D-46; public/perfil.html).
//   GET  ?accion=ver[&id=]   el propio perfil; con id, el de otro usuario de la empresa
//                            (solo Administrador o soporte de plataforma)
//   POST ?accion=subir_foto  multipart "foto": SIEMPRE la del usuario en sesión
//   POST ?accion=quitar_foto la del usuario en sesión (se ignora cualquier id)
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../auth/audit.php';
require_once __DIR__ . '/../config/demo.php';
require_once __DIR__ . '/lib/perfil_helpers.php';

header('Content-Type: application/json');

function responderPerfil(int $codigo, array $datos): void {
    http_response_code($codigo);
    echo json_encode(['exito' => $codigo < 400] + $datos, JSON_UNESCAPED_UNICODE);
    exit;
}

$accion = $_GET['accion'] ?? 'ver';
$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$yo = (int)($_SESSION['user_id'] ?? 0);
$rol = $_SESSION['rol'] ?? '';

try {
    switch ("$metodo:$accion") {
        case 'GET:ver':
            $id = (int)($_GET['id'] ?? 0) ?: $yo;
            $veTodos = $rol === 'Administrador' || (defined('ROL_ACCESO_TOTAL') && $rol === ROL_ACCESO_TOTAL);
            if ($id !== $yo && !$veTodos) responderPerfil(403, ['mensaje' => 'Solo puedes ver tu propio perfil.']);
            $perfil = perfilVer($pdo, $id);
            if (!$perfil) responderPerfil(404, ['mensaje' => 'El usuario no existe.']);
            $perfil['propio'] = $id === $yo;
            responderPerfil(200, $perfil);

        case 'POST:subir_foto':
            demoBloquear('cambiar la foto de perfil');
            $archivo = $_FILES['foto'] ?? [];
            if (($archivo['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_INI_SIZE || ($archivo['error'] ?? 0) === UPLOAD_ERR_FORM_SIZE) {
                throw new InvalidArgumentException('La foto pesa más de 1 MB. Elige una más ligera.');
            }
            if (($archivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($archivo['tmp_name'] ?? '')) {
                throw new InvalidArgumentException('No se recibió la foto. Inténtalo de nuevo.');
            }
            $ruta = perfilGuardarFoto($pdo, $yo, $archivo['tmp_name']);
            registrarAuditoria('usuarios', $yo, 'UPDATE', null, ['foto' => 'actualizada'], $_SESSION['nombre'] ?? null);
            responderPerfil(200, ['mensaje' => 'Foto actualizada.', 'foto' => $ruta]);

        case 'POST:quitar_foto':
            demoBloquear('cambiar la foto de perfil');
            perfilQuitarFoto($pdo, $yo);
            registrarAuditoria('usuarios', $yo, 'UPDATE', null, ['foto' => 'quitada'], $_SESSION['nombre'] ?? null);
            responderPerfil(200, ['mensaje' => 'Foto quitada.']);

        default:
            responderPerfil(404, ['mensaje' => 'Acción no reconocida.']);
    }
} catch (InvalidArgumentException $e) {
    responderPerfil(400, ['mensaje' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('perfil.php: ' . $e->getMessage());
    responderPerfil(500, ['mensaje' => 'No se pudo completar la operación.']);
}
