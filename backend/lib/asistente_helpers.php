<?php
// Asistente guiado por rol (D-20). El progreso vive en usuarios.asistente (migración 013):
// NULL = pendiente (se abre solo), 'cerrado' o 'terminado' (ya no se abre solo; se puede
// volver a abrir desde Ayuda -> Recorrido guiado). Requiere $pdo.

const ASISTENTE_ESTADOS = ['cerrado', 'terminado'];

function asistenteDisponible(PDO $pdo): bool {
    static $hay = null;
    if ($hay === null) {
        try {
            $hay = (bool)$pdo->query("SHOW COLUMNS FROM usuarios LIKE 'asistente'")->fetch();
        } catch (Throwable $e) {
            $hay = false;
        }
    }
    return $hay;
}

/** 'nuevo', 'cerrado' o 'terminado'; null si falta la migración 013 o el usuario no es de la empresa. */
function asistenteEstado(PDO $pdo, int $usuarioId, int $empresaId): ?string {
    if (!asistenteDisponible($pdo)) return null;
    $st = $pdo->prepare('SELECT asistente FROM usuarios WHERE id = ? AND empresa_id = ?');
    $st->execute([$usuarioId, $empresaId]);
    $valor = $st->fetchColumn();
    if ($valor === false) return null;
    return in_array($valor, ASISTENTE_ESTADOS, true) ? $valor : 'nuevo';
}

/** Guarda el progreso del usuario (solo dentro de su empresa). */
function asistenteGuardar(PDO $pdo, int $usuarioId, int $empresaId, string $estado): void {
    if (!in_array($estado, ASISTENTE_ESTADOS, true)) throw new InvalidArgumentException('Estado del asistente no válido.');
    if (!asistenteDisponible($pdo)) return;
    $pdo->prepare('UPDATE usuarios SET asistente = ? WHERE id = ? AND empresa_id = ?')->execute([$estado, $usuarioId, $empresaId]);
}
