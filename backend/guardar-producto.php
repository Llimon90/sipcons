<?php
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../auth/permisos.php';
require_once __DIR__ . '/../auth/audit.php';
requirePermiso('inventario');
header('Content-Type: application/json');

try {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];

    $sku = trim((string)($data['sku'] ?? ''));
    $nombre = trim((string)($data['nombre'] ?? ''));
    $descripcion = trim((string)($data['descripcion'] ?? ''));
    $unidad = trim((string)($data['unidad'] ?? 'pza')) ?: 'pza';
    $stockInicial = max(0, (int)($data['stock_actual'] ?? 0));
    $stockMinimo = max(0, (int)($data['stock_minimo'] ?? 0));
    $costo = max(0, (float)($data['costo'] ?? 0));
    $precioVenta = max(0, (float)($data['precio_venta'] ?? 0));

    if ($sku === '' || $nombre === '') {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'SKU y nombre son obligatorios.']);
        exit;
    }

    $pdo->beginTransaction();

    $stmt = $pdo->prepare(
        'INSERT INTO inventario_productos (sku, nombre, descripcion, unidad, stock_actual, stock_minimo, costo, precio_venta)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$sku, $nombre, $descripcion, $unidad, $stockInicial, $stockMinimo, $costo, $precioVenta]);
    $id = (int)$pdo->lastInsertId();

    if ($stockInicial > 0) {
        require_once __DIR__ . '/lib/inventario_helpers.php';
        // Movimiento inicial informativo: no vuelve a tocar stock_actual (ya
        // se guardó arriba), así que se registra directo, sin ajustarStockInventario.
        $pdo->prepare(
            'INSERT INTO inventario_movimientos (producto_id, tipo, cantidad, motivo, usuario_id, usuario_nombre)
             VALUES (?, "Entrada", ?, ?, ?, ?)'
        )->execute([$id, $stockInicial, 'Alta de producto (stock inicial)', $_SESSION['user_id'] ?? null, $_SESSION['nombre'] ?? ($_SESSION['usuario'] ?? null)]);
    }

    $pdo->commit();

    registrarAuditoria('inventario_productos', $id, 'CREATE', null, [
        'sku' => $sku, 'nombre' => $nombre, 'stock_actual' => $stockInicial, 'stock_minimo' => $stockMinimo,
        'costo' => $costo, 'precio_venta' => $precioVenta,
    ]);

    echo json_encode(['success' => true, 'id' => $id]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $esDuplicado = $e instanceof PDOException && (int)$e->getCode() === 23000;
    http_response_code($esDuplicado ? 409 : 500);
    echo json_encode(['success' => false, 'message' => $esDuplicado ? 'Ya existe un producto con ese SKU.' : 'Error al guardar el producto.']);
}
