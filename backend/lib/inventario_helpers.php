<?php
// Ajuste de stock de inventario. Debe llamarse dentro de una transacción ya
// abierta por el endpoint (no abre ni cierra transacción aquí) para que, si el
// movimiento es parte de una operación más grande (ej. una venta que consume
// refacciones), todo se guarde o nada se guarde.

// Categorías reales de Servidesk (backend/lib/inventario_helpers.php allá).
// No se portan 'Servicio'/'Mano de obra' (conceptos sin existencia, D-23 de
// Servidesk): el inventario de SIPCONS por ahora solo controla piezas
// físicas.
const INV_CATEGORIAS = ['Equipo', 'Refacción', 'Consumible', 'Herramienta'];

/**
 * Cuánto de un producto sigue consumido (neto) en una incidencia: lo que
 * salió por esa referencia menos lo que ya se devolvió. Sirve para no dejar
 * devolver más de lo que de verdad está pendiente.
 */
function consumoNetoProductoIncidencia(PDO $pdo, int $incidenciaId, int $productoId): int {
    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(CASE WHEN tipo = 'Salida' THEN cantidad WHEN tipo = 'Entrada' THEN -cantidad ELSE 0 END), 0)
         FROM inventario_movimientos
         WHERE producto_id = ? AND referencia_tipo = 'incidencia' AND referencia_id = ?"
    );
    $stmt->execute([$productoId, $incidenciaId]);
    return (int)$stmt->fetchColumn();
}

/**
 * @throws RuntimeException si el producto no existe o si una Salida deja el
 *                           stock en negativo.
 */
function ajustarStockInventario(
    PDO $pdo,
    int $productoId,
    string $tipo,
    int $cantidad,
    ?string $motivo = null,
    ?string $referenciaTipo = null,
    ?int $referenciaId = null
): array {
    if (!in_array($tipo, ['Entrada', 'Salida', 'Ajuste'], true)) {
        throw new RuntimeException('Tipo de movimiento inválido.');
    }
    if ($cantidad <= 0) {
        throw new RuntimeException('La cantidad debe ser mayor a cero.');
    }

    $stmt = $pdo->prepare('SELECT stock_actual FROM inventario_productos WHERE id = ? FOR UPDATE');
    $stmt->execute([$productoId]);
    $fila = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$fila) {
        throw new RuntimeException('El producto no existe.');
    }

    $stockActual = (int)$fila['stock_actual'];
    $delta = $tipo === 'Salida' ? -$cantidad : $cantidad;
    $nuevoStock = $stockActual + $delta;

    if ($nuevoStock < 0) {
        throw new RuntimeException('No hay suficiente stock disponible para esta salida.');
    }

    $pdo->prepare('UPDATE inventario_productos SET stock_actual = ? WHERE id = ?')
        ->execute([$nuevoStock, $productoId]);

    $pdo->prepare(
        'INSERT INTO inventario_movimientos
            (producto_id, tipo, cantidad, motivo, referencia_tipo, referencia_id, usuario_id, usuario_nombre)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $productoId, $tipo, $cantidad, $motivo, $referenciaTipo, $referenciaId,
        $_SESSION['user_id'] ?? null,
        $_SESSION['nombre'] ?? ($_SESSION['usuario'] ?? null),
    ]);

    return ['stock_anterior' => $stockActual, 'stock_actual' => $nuevoStock];
}
