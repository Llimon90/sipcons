<?php
// Historial de cambios (auditoría). Requiere que config/database.php ya
// haya corrido (expone $pdo) y que la sesión esté iniciada (config/auth.php).

// Nunca debe interrumpir la operación principal: si el registro de
// auditoría falla, solo se anota en el error_log del servidor.
/**
 * Historial de algo que hizo el sistema, no un usuario (D-24: tickets automáticos).
 * Queda como "Sistema" aunque la acción se dispare durante la petición de alguien.
 */
function registrarAuditoriaSistema(string $tabla, $registroId, string $accion, ?array $datosAnteriores, ?array $datosNuevos, ?string $registroFolio = null): void {
    $sesion = $_SESSION ?? [];
    $_SESSION = ['nombre' => 'Sistema', 'rol' => 'Sistema'] + array_diff_key($sesion, array_flip(['user_id', 'nombre', 'usuario', 'rol']));
    try {
        registrarAuditoria($tabla, $registroId, $accion, $datosAnteriores, $datosNuevos, $registroFolio);
    } finally {
        $_SESSION = $sesion;
    }
}

function registrarAuditoria(
    string $tabla,
    $registroId,
    string $accion,
    ?array $datosAnteriores,
    ?array $datosNuevos,
    ?string $registroFolio = null
): void {
    global $pdo;

    if (!isset($pdo) || !($pdo instanceof PDO)) {
        error_log("Auditoría no registrada (sin conexión PDO): $tabla/$accion");
        return;
    }

    try {
        $stmt = $pdo->prepare(
            "INSERT INTO auditoria
                (usuario_id, usuario_nombre, usuario_rol, accion, tabla, registro_id, registro_folio, datos_anteriores, datos_nuevos, ip_address)
             VALUES
                (:usuario_id, :usuario_nombre, :usuario_rol, :accion, :tabla, :registro_id, :registro_folio, :datos_anteriores, :datos_nuevos, :ip_address)"
        );

        $stmt->execute([
            ':usuario_id'       => $_SESSION['user_id'] ?? null,
            ':usuario_nombre'   => $_SESSION['nombre'] ?? ($_SESSION['usuario'] ?? null),
            ':usuario_rol'      => $_SESSION['rol'] ?? null,
            ':accion'           => $accion,
            ':tabla'            => $tabla,
            ':registro_id'      => $registroId !== null ? (string)$registroId : null,
            ':registro_folio'   => $registroFolio,
            ':datos_anteriores' => $datosAnteriores !== null ? json_encode($datosAnteriores, JSON_UNESCAPED_UNICODE) : null,
            ':datos_nuevos'     => $datosNuevos !== null ? json_encode($datosNuevos, JSON_UNESCAPED_UNICODE) : null,
            ':ip_address'       => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (\Throwable $e) {
        error_log("Error registrando auditoría ($tabla/$accion): " . $e->getMessage());
    }
}
