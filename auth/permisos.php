<?php
// Privilegios configurables por rol (tabla permisos_rol). Requiere que
// config/auth.php ya haya corrido (sesión activa) y config/database.php
// (expone $pdo).

// Soporte de plataforma: acceso total, fijo en el código. No es un rol que se
// asigne a usuarios: lo toma la cuenta de plataforma al entrar a una empresa
// en modo soporte (config/plataforma.php). No pasa por permisos_rol.
const ROL_ACCESO_TOTAL = 'Soporte de plataforma';

// Módulos válidos que puede configurar el checklist de privilegios.
const MODULOS_PERMISOS = ['incidencias', 'reportes', 'clientes', 'cotizaciones', 'ventas', 'inventario', 'finanzas', 'usuarios', 'soporte', 'informes', 'historial'];

// Roles que se pueden asignar a un usuario (migración 006: los antiguos
// Técnico/Administrador y Programador pasaron a Administrador).
const ROLES_ASIGNABLES = ['Administrador', 'Supervisor', 'Administrativo', 'Técnico'];

// Roles que se configuran desde el checklist de Ajustes → Privilegios.
const ROLES_CONFIGURABLES = ROLES_ASIGNABLES;

// Roles que administran la empresa: usuarios, privilegios, Mi empresa,
// eliminar registros. Chequeo fijo, no depende de permisos_rol.
const ROLES_ADMINISTRACION = ['Administrador', ROL_ACCESO_TOTAL];

function esAdministracion(): bool {
    return in_array($_SESSION['rol'] ?? '', ROLES_ADMINISTRACION, true);
}

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

// Exige al menos uno de los módulos: para endpoints compartidos por varias
// pantallas (p. ej. el catálogo de clientes lo usan incidencias, ventas y búsquedas).
function requirePermisoAlguno(string ...$modulos): void {
    requireAuth();
    foreach ($modulos as $modulo) {
        if (tienePermiso($modulo)) return;
    }
    http_response_code(403);
    header('Content-Type: application/json');
    die(json_encode(['error' => 'Acceso denegado', 'exito' => false, 'success' => false, 'mensaje' => 'Tu rol no tiene acceso a este módulo.']));
}

// Solo el Administrador (o soporte de plataforma) edita el checklist de privilegios.
// A propósito NO usa requirePermiso() (que consulta la misma tabla que se
// va a editar): mantenerlo como chequeo de rol fijo evita que un rol mal
// configurado pueda auto-otorgarse permisos.
function requireGestionPrivilegios(): void {
    requireAuth();
    if (!esAdministracion()) {
        http_response_code(403);
        header('Content-Type: application/json');
        die(json_encode(['error' => 'Acceso denegado']));
    }
}
