<?php
// Contactos de cliente (tabla cliente_contactos, migración 007).
// Requiere $pdo (config/database.php). Si la tabla aún no existe, las
// funciones no rompen nada: leer devuelve [] y sincronizar solo registra el
// error, de modo que el texto clientes.contactos sigue siendo la fuente.

// Normaliza la lista recibida (JSON del formulario) a filas válidas.
function normalizarContactos($json): array {
    $lista = is_array($json) ? $json : json_decode((string)$json, true);
    if (!is_array($lista)) {
        return [];
    }

    $salida = [];
    $vistos = [];
    foreach ($lista as $c) {
        $nombre = trim((string)($c['nombre'] ?? ''));
        $nombre = trim(str_replace(';', ',', $nombre)); // ";" es el separador del texto sincronizado
        if ($nombre === '' || isset($vistos[mb_strtolower($nombre)])) {
            continue;
        }
        $vistos[mb_strtolower($nombre)] = true;
        $salida[] = [
            'nombre'   => mb_substr($nombre, 0, 150),
            'telefono' => mb_substr(trim((string)($c['telefono'] ?? '')), 0, 50),
            'email'    => mb_substr(trim((string)($c['email'] ?? '')), 0, 150),
        ];
    }
    return $salida;
}

// Texto para clientes.contactos: nombres separados por "; ".
function contactosComoTexto(array $contactos): string {
    return implode('; ', array_column($contactos, 'nombre'));
}

function obtenerContactosCliente(int $clienteId): array {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT nombre, telefono, email FROM cliente_contactos WHERE cliente_id = ? ORDER BY id");
        $stmt->execute([$clienteId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('Contactos de cliente no disponibles: ' . $e->getMessage());
        return [];
    }
}

// Reemplaza los contactos del cliente por la lista recibida.
function sincronizarContactosCliente(int $clienteId, array $contactos): void {
    global $pdo;
    try {
        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM cliente_contactos WHERE cliente_id = ?")->execute([$clienteId]);
        $ins = $pdo->prepare("INSERT INTO cliente_contactos (cliente_id, nombre, telefono, email) VALUES (?, ?, ?, ?)");
        foreach ($contactos as $c) {
            $ins->execute([$clienteId, $c['nombre'], $c['telefono'] ?: null, $c['email'] ?: null]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('No se pudieron guardar los contactos del cliente ' . $clienteId . ': ' . $e->getMessage());
    }
}
