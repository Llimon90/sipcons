<?php
// Preferencias personales del usuario, guardadas en su cuenta (usuarios.preferencias,
// migración 014). Solo claves conocidas: no es un almacén libre. Requiere $pdo.

// clave => tipo
const PREFERENCIAS_CLAVES = [
    'cot_sin_confirmar_envio' => 'bool',  // O-15: ya leyó qué pasa al enviar una cotización
];

function preferenciasDisponibles(PDO $pdo): bool {
    static $hay = null;
    if ($hay === null) {
        try {
            $hay = (bool)$pdo->query("SHOW COLUMNS FROM usuarios LIKE 'preferencias'")->fetch();
        } catch (Throwable $e) {
            $hay = false;
        }
    }
    return $hay;
}

function preferenciasLeer(PDO $pdo, int $usuarioId, int $empresaId): array {
    if (!preferenciasDisponibles($pdo)) return [];
    $st = $pdo->prepare('SELECT preferencias FROM usuarios WHERE id = ? AND empresa_id = ?');
    $st->execute([$usuarioId, $empresaId]);
    $p = json_decode((string)$st->fetchColumn(), true);
    return is_array($p) ? array_intersect_key($p, PREFERENCIAS_CLAVES) : [];
}

function preferenciasGuardar(PDO $pdo, int $usuarioId, int $empresaId, string $clave, $valor): array {
    if (!array_key_exists($clave, PREFERENCIAS_CLAVES)) throw new InvalidArgumentException('Preferencia no válida.');
    if (PREFERENCIAS_CLAVES[$clave] === 'bool') $valor = (bool)$valor;
    if (!preferenciasDisponibles($pdo)) return [];
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT preferencias FROM usuarios WHERE id = ? AND empresa_id = ? FOR UPDATE');
        $st->execute([$usuarioId, $empresaId]);
        $actual = json_decode((string)$st->fetchColumn(), true);
        $actual = is_array($actual) ? array_intersect_key($actual, PREFERENCIAS_CLAVES) : [];
        $actual[$clave] = $valor;
        $pdo->prepare('UPDATE usuarios SET preferencias = ? WHERE id = ? AND empresa_id = ?')
            ->execute([json_encode($actual, JSON_UNESCAPED_UNICODE), $usuarioId, $empresaId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return $actual;
}
