<?php
// Cuentas de acceso. El usuario para iniciar sesión es el correo, único en
// toda la plataforma: así dos empresas nunca chocan por un nombre como
// "tecnico1". Las cuentas anteriores con un usuario que no es correo siguen
// entrando igual hasta que se les cambie el correo.
//
// Requiere $pdo (config/database.php).

const MIN_PASSWORD_USUARIO = 8;

/** ¿Ya se aplicó la migración 021 (usuarios.debe_cambiar_password; L-13)? */
function usuariosTienenCambioPassword(PDO $pdo): bool {
    static $hay = null;
    if ($hay === null) {
        try { $hay = (bool)$pdo->query("SHOW COLUMNS FROM usuarios LIKE 'debe_cambiar_password'")->fetch(); } catch (Throwable $e) { $hay = false; }
    }
    return $hay;
}

/** L-13: ¿el usuario debe cambiar su contraseña temporal antes de usar el sistema? */
function usuarioDebeCambiarPassword(PDO $pdo, int $usuarioId): bool {
    if ($usuarioId <= 0 || !usuariosTienenCambioPassword($pdo)) return false;
    $st = $pdo->prepare('SELECT debe_cambiar_password FROM usuarios WHERE id = ?');
    $st->execute([$usuarioId]);
    return (int)$st->fetchColumn() === 1;
}

// Las cuentas de Administrador solo las crea, edita o elimina otro
// Administrador (o soporte de plataforma). El módulo "usuarios" se puede
// otorgar a otros roles desde Privilegios, pero sin esto quien lo tuviera
// podría ascenderse o tomar la cuenta del dueño.
const ROL_ADMINISTRADOR = 'Administrador';

/** Mensaje de error si el usuario en sesión no puede tocar esa cuenta; null si puede. */
function errorGestionCuenta(?string $rolActual, ?string $rolNuevo): ?string {
    if (esAdministracion()) return null;
    if ($rolActual === ROL_ADMINISTRADOR || $rolNuevo === ROL_ADMINISTRADOR) {
        return 'Solo un Administrador puede crear, modificar o eliminar cuentas de Administrador.';
    }
    return null;
}

/** Administradores de la empresa (la empresa no puede quedarse sin ninguno). */
function contarAdministradores(PDO $pdo, int $empresaId): int {
    $st = $pdo->prepare('SELECT COUNT(*) FROM usuarios WHERE empresa_id = ? AND rol = ?');
    $st->execute([$empresaId, ROL_ADMINISTRADOR]);
    return (int)$st->fetchColumn();
}

function normalizarCorreo(string $correo): string {
    return strtolower(trim($correo));
}

/** Mensaje de error si el correo no sirve como usuario, o null si es válido. */
function errorCorreoUsuario(string $correo): ?string {
    if ($correo === '') return 'El correo es obligatorio: será su usuario para iniciar sesión.';
    if (strlen($correo) > 100 || !filter_var($correo, FILTER_VALIDATE_EMAIL)) return 'Escribe un correo válido (por ejemplo nombre@empresa.com).';
    return null;
}

/**
 * ¿El correo está libre en toda la plataforma? Se compara contra el usuario y
 * el correo de todas las cuentas, de cualquier empresa ($excluirId: la cuenta
 * que se está editando).
 */
function correoDisponible(PDO $pdo, string $correo, ?int $excluirId = null): bool {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE (LOWER(usuario) = ? OR LOWER(correo) = ?) AND id <> ?");
    $stmt->execute([$correo, $correo, (int)$excluirId]);
    return (int)$stmt->fetchColumn() === 0;
}

// No dice de qué empresa es la cuenta: no se revelan datos de otros clientes
const MENSAJE_CORREO_OCUPADO = 'Ese correo ya tiene una cuenta en el sistema. Usa otro correo para este usuario.';
