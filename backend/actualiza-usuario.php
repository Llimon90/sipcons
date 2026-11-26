<?php
// ==============================================
// 1. Dependencias Críticas
// ==============================================
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../auth/audit.php';
require_once __DIR__ . '/../auth/permisos.php';
requirePermiso('usuarios');
require_once __DIR__ . '/../config/database.php'; // <-- Agregada la conexión a la BD
require_once __DIR__ . '/../config/usuarios.php';

header('Content-Type: application/json');
ini_set('display_errors', 0);

$data = json_decode(file_get_contents('php://input'), true);

// Verificar que llegaron datos válidos
if (!$data || !isset($data['id'])) {
    http_response_code(400);
    die(json_encode(['success' => false, 'message' => 'Datos incompletos o inválidos']));
}

require_once __DIR__ . '/../config/demo.php';
if (enEmpresaDemo()) {
    demoBloquear('modificar la cuenta de demostración', esCuentaDemo($pdo, $data['id']));
    demoBloquear('el cambio de contraseña', !empty($data['password']));
}

try {
    $id = (int)$data['id'];
    $nombre = trim((string)($data['nombre'] ?? ''));
    $correo = normalizarCorreo((string)($data['correo'] ?? ''));
    $telefono = trim((string)($data['telefono'] ?? ''));
    $rol = $data['rol'] ?? '';
    if ($nombre === '') {
        http_response_code(422);
        die(json_encode(['success' => false, 'message' => 'El nombre es obligatorio.']));
    }
    if ($error = errorCorreoUsuario($correo)) {
        http_response_code(422);
        die(json_encode(['success' => false, 'message' => $error], JSON_UNESCAPED_UNICODE));
    }
    if (!empty($data['password']) && strlen((string)$data['password']) < MIN_PASSWORD_USUARIO) {
        http_response_code(422);
        die(json_encode(['success' => false, 'message' => 'La contraseña debe tener al menos ' . MIN_PASSWORD_USUARIO . ' caracteres.'], JSON_UNESCAPED_UNICODE));
    }

    // Estado anterior para el historial (nunca se incluye la contraseña)
    $empresaId = (int)$_SESSION['empresa_id'];
    if (!in_array($rol, ROLES_ASIGNABLES, true)) {
        http_response_code(400);
        die(json_encode(['success' => false, 'message' => 'Rol no válido']));
    }
    $stmtAnterior = $conn->prepare("SELECT nombre, correo, telefono, usuario, rol" . (usuariosTienePuedeCerrar() ? ", puede_cerrar" : "")
        . (usuariosTieneAutorizaDescuentos() ? ", puede_autorizar_descuentos" : "") . " FROM usuarios WHERE id = ? AND empresa_id = ?");
    $stmtAnterior->bind_param("ii", $id, $empresaId);
    $stmtAnterior->execute();
    $anterior = $stmtAnterior->get_result()->fetch_assoc();
    $stmtAnterior->close();
    if (!$anterior) {
        http_response_code(404);
        die(json_encode(['success' => false, 'message' => 'Usuario no encontrado']));
    }
    $esPropia = $id === (int)($_SESSION['user_id'] ?? 0);
    $rolCambia = normalizarRol((string)$anterior['rol']) !== $rol;
    $errorPermiso = errorGestionCuenta(normalizarRol((string)$anterior['rol']), $rol);
    if (!$errorPermiso && $esPropia && $rolCambia && !esAdministracion()) {
        $errorPermiso = 'No puedes cambiar tu propio rol. Pídeselo a un Administrador.';
    }
    if (!$errorPermiso && $rolCambia && normalizarRol((string)$anterior['rol']) === ROL_ADMINISTRADOR
        && contarAdministradores($pdo, $empresaId) <= 1) {
        $errorPermiso = 'Es el único Administrador de la empresa: asigna ese rol a otra persona antes de cambiar el suyo.';
    }
    if ($errorPermiso) {
        http_response_code(403);
        die(json_encode(['success' => false, 'message' => $errorPermiso], JSON_UNESCAPED_UNICODE));
    }

    // El correo es el usuario de acceso: si cambia, el usuario cambia con él.
    // Si no cambia, la cuenta conserva su usuario (las anteriores sin correo
    // como usuario siguen entrando igual).
    $correoCambio = $correo !== normalizarCorreo((string)$anterior['correo']);
    $usuario = $correoCambio ? $correo : $anterior['usuario'];
    if ($correoCambio && !correoDisponible($pdo, $correo, $id)) {
        http_response_code(422);
        die(json_encode(['success' => false, 'message' => MENSAJE_CORREO_OCUPADO], JSON_UNESCAPED_UNICODE));
    }

    // Preparar la consulta base (usando tu objeto $conn de mysqli que viene en database.php)
    // AS-7: el nombre identifica al técnico en las órdenes: sin "/" y único en la empresa
    require_once __DIR__ . '/lib/tecnicos_helpers.php';
    if ($errorNombre = errorNombreUsuario($pdo, $nombre, $empresaId, $id)) {
        http_response_code(422);
        die(json_encode(['success' => false, 'message' => $errorNombre], JSON_UNESCAPED_UNICODE));
    }
    $stIdent = $pdo->prepare('SELECT ' . sqlIdentidadUsuario($pdo) . ' AS identidad, TRIM(nombre) AS nombre FROM usuarios WHERE id = ? AND empresa_id = ?');
    $stIdent->execute([$id, $empresaId]);
    $identAntes = $stIdent->fetch(PDO::FETCH_ASSOC) ?: ['identidad' => '', 'nombre' => ''];

    $sql = "UPDATE usuarios SET nombre = ?, correo = ?, telefono = ?, usuario = ?, rol = ?";
    $params = [$nombre, $correo, $telefono, $usuario, $rol];
    $types = "sssss";

    // Permiso de cerrar incidencias (Cerrado con/sin factura); sin la
    // migración 003 la columna no existe y se omite.
    // P-H05: solo un Administrador cambia estas banderas; si no vienen, se conservan
    $puedeCerrar = (esAdministracion() && array_key_exists('puede_cerrar', $data))
        ? (!empty($data['puede_cerrar']) ? 1 : 0) : (int)($anterior['puede_cerrar'] ?? 1);
    if (usuariosTienePuedeCerrar()) {
        $sql .= ", puede_cerrar = ?";
        $params[] = $puedeCerrar;
        $types .= "i";
    }
    // Cotizaciones: puede rebasar el descuento máximo de la empresa (migración 007)
    $autorizaDescuentos = (esAdministracion() && array_key_exists('puede_autorizar_descuentos', $data))
        ? (!empty($data['puede_autorizar_descuentos']) ? 1 : 0) : (int)($anterior['puede_autorizar_descuentos'] ?? 0);
    if (usuariosTieneAutorizaDescuentos()) {
        $sql .= ", puede_autorizar_descuentos = ?";
        $params[] = $autorizaDescuentos;
        $types .= "i";
    }
    
    // Si se proporcionó una nueva contraseña
    if (!empty($data['password'])) {
        $hashedPassword = password_hash($data['password'], PASSWORD_DEFAULT);
        $sql .= ", password = ?";
        $params[] = $hashedPassword;
        $types .= "s";
        // H-11 · L-13: la contraseña que asigna un administrador ya no es la temporal del panel
        require_once __DIR__ . '/../config/usuarios.php';
        if (usuariosTienenCambioPassword($pdo)) $sql .= ", debe_cambiar_password = 0";
    }
    
    $sql .= " WHERE id = ? AND empresa_id = ?";
    $params[] = $id;
    $params[] = $empresaId;
    $types .= "ii";
    
    // H-04 (paso 8): el usuario y sus órdenes se guardan juntos o no se guarda nada
    $pdo->beginTransaction();
    $pdo->prepare($sql)->execute($params);
    {
        // D-32: si deja de ser asignable sale de sus órdenes activas; si cambia el nombre
        // (y no usa un alias distinto), sus órdenes activas toman el nombre nuevo.
        $identidadAntes = (string)$identAntes['identidad'];
        if ($identidadAntes !== '') {
            if (!in_array($rol, ROLES_TECNICO_ASIGNABLE, true) && in_array(normalizarRol((string)$anterior['rol']), ROLES_TECNICO_ASIGNABLE, true)) {
                reemplazarTecnicoEnOrdenesActivas($pdo, $identidadAntes, null, "$identidadAntes cambió al rol $rol");
            } elseif ($identidadAntes === (string)$identAntes['nombre'] && $nombre !== $identidadAntes
                      && in_array(normalizarRol((string)$anterior['rol']), ROLES_TECNICO_ASIGNABLE, true)) {
                reemplazarTecnicoEnOrdenesActivas($pdo, $identidadAntes, $nombre, "Cambio de nombre: $identidadAntes -> $nombre");
            }
        }
        $pdo->commit();
        registrarAuditoria('usuarios', $id, 'UPDATE', $anterior ?: null, [
            'nombre'            => $nombre,
            'correo'            => $correo,
            'telefono'          => $telefono,
            'usuario'           => $usuario,
            'rol'               => $rol,
            'puede_cerrar'      => $puedeCerrar,
            'puede_autorizar_descuentos' => $autorizaDescuentos,
            'password_cambiada' => !empty($data['password']),
        ]);
        // Si el usuario se editó a sí mismo, la sesión refleja su nuevo acceso
        if ($esPropia) {
            $_SESSION['usuario'] = $usuario;
            if (!empty($hashedPassword)) actualizarHuellaSesion($hashedPassword);
        }
        echo json_encode(['success' => true, 'acceso_cambiado' => $usuario !== $anterior['usuario'], 'usuario' => $usuario], JSON_UNESCAPED_UNICODE);
    }
    
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    $duplicado = ($e instanceof mysqli_sql_exception && $e->getCode() === 1062)
        || ($e instanceof PDOException && ($e->errorInfo[1] ?? 0) === 1062);
    $nombreRepetido = $duplicado && str_contains($e->getMessage(), 'uniq_empresa_nombre');
    if (!$duplicado) error_log('Edición de usuario: ' . $e->getMessage());
    http_response_code($duplicado ? 422 : 500);
    echo json_encode(['success' => false, 'message' => $nombreRepetido
        ? 'Ya hay otro usuario con ese nombre en la empresa. Agrega un apellido o una inicial para distinguirlos.'
        : ($duplicado ? MENSAJE_CORREO_OCUPADO : 'No se pudo guardar el usuario. Inténtalo de nuevo.')], JSON_UNESCAPED_UNICODE);
}
?>