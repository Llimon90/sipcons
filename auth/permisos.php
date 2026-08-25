<?php
// Privilegios configurables por rol (tabla permisos_rol). Requiere que
// config/auth.php ya haya corrido (sesión activa) y config/database.php
// (expone $pdo).

// Programador: modo mantenimiento/desarrollador, siempre con acceso total.
// No pasa por la tabla permisos_rol ni por el checklist de Ajustes.
const ROL_ACCESO_TOTAL = 'Programador';

// Módulos válidos que puede configurar el checklist de privilegios.
const MODULOS_PERMISOS = ['incidencias', 'reportes', 'clientes', 'ventas', 'usuarios', 'soporte', 'informes', 'historial'];

// Roles que se pueden asignar a un usuario. "Programador" está incluido
// (es un rol asignable, para quien da mantenimiento al sistema) pero NO
// aparece en el checklist de privilegios: su acceso total es fijo en el
// código (ver tienePermiso), no depende de la tabla permisos_rol.
const ROLES_ASIGNABLES = ['Técnico', 'Administrativo', 'Técnico/Administrativo', 'Técnico/Administrador', 'Administrador', ROL_ACCESO_TOTAL];

// Roles que sí se configuran desde el checklist de Ajustes → Privilegios.
const ROLES_CONFIGURABLES = ['Técnico', 'Administrativo', 'Técnico/Administrativo', 'Técnico/Administrador', 'Administrador'];

function tienePermiso(string $modulo): bool {
    global $pdo;

    $rol = $_SESSION['rol'] ?? '';
    if ($rol === ROL_ACCESO_TOTAL) {
        return true;
    }

    if (!isset($pdo) || !($pdo instanceof PDO)) {
        error_log("Permiso denegado (sin conexión PDO): $rol/$modulo");
        return false;
    }

    try {
        $stmt = $pdo->prepare("SELECT permitido FROM permisos_rol WHERE rol = ? AND modulo = ? LIMIT 1");
        $stmt->execute([$rol, $modulo]);
        $fila = $stmt->fetch(PDO::FETCH_ASSOC);
        return $fila ? (bool)$fila['permitido'] : false;
    } catch (\Throwable $e) {
        error_log("Error verificando permiso ($rol/$modulo): " . $e->getMessage());
        // Ante un fallo de BD, negamos el acceso en vez de concederlo.
        return false;
    }
}

// Devuelve la lista de módulos a los que el usuario en sesión tiene acceso.
function modulosPermitidos(): array {
    global $pdo;

    $rol = $_SESSION['rol'] ?? '';
    if ($rol === ROL_ACCESO_TOTAL) {
        return MODULOS_PERMISOS;
    }

    if (!isset($pdo) || !($pdo instanceof PDO)) {
        return [];
    }

    try {
        $stmt = $pdo->prepare("SELECT modulo FROM permisos_rol WHERE rol = ? AND permitido = 1");
        $stmt->execute([$rol]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (\Throwable $e) {
        error_log("Error obteniendo módulos permitidos ($rol): " . $e->getMessage());
        return [];
    }
}

function requirePermiso(string $modulo): void {
    requireAuth();
    if (!tienePermiso($modulo)) {
        http_response_code(403);
        header('Content-Type: application/json');
        die(json_encode(['error' => 'Acceso denegado']));
    }
}

// Solo Administrador o Programador pueden editar el checklist de privilegios.
// A propósito NO usa requirePermiso() (que consulta la misma tabla que se
// va a editar): mantenerlo como chequeo de rol fijo evita que un rol mal
// configurado pueda auto-otorgarse permisos.
function requireGestionPrivilegios(): void {
    requireAuth();
    $rol = $_SESSION['rol'] ?? '';
    if ($rol !== 'Administrador' && $rol !== ROL_ACCESO_TOTAL) {
        http_response_code(403);
        header('Content-Type: application/json');
        die(json_encode(['error' => 'Acceso denegado']));
    }
}
