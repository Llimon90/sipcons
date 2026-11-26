<?php
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../auth/permisos.php';
require_once __DIR__ . '/../auth/audit.php';
requirePermiso('inventario');
header('Content-Type: application/json');

try {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $id = (int)($data['id'] ?? 0);
    if ($id <= 0) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Falta el id del producto.']);
        exit;
    }

    $stmt = $pdo->prepare('SELECT * FROM inventario_productos WHERE id = ?');
    $stmt->execute([$id]);
    $anterior = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$anterior) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Producto no encontrado.']);
        exit;
    }

    $nombre = trim((string)($data['nombre'] ?? $anterior['nombre']));
    $descripcion = trim((string)($data['descripcion'] ?? $anterior['descripcion']));
    $unidad = trim((string)($data['unidad'] ?? $anterior['unidad'])) ?: $anterior['unidad'];
    $stockMinimo = max(0, (int)($data['stock_minimo'] ?? $anterior['stock_minimo']));
    $costo = max(0, (float)($data['costo'] ?? $anterior['costo']));
    $precioVenta = max(0, (float)($data['precio_venta'] ?? $anterior['precio_venta']));
    $activo = isset($data['activo']) ? (int)!!$data['activo'] : (int)$anterior['activo'];

    if ($nombre === '') {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'El nombre es obligatorio.']);
        exit;
    }

    // El stock_actual NO se edita aquí: solo se mueve con movimientos
    // registrados (backend/registrar-movimiento-inventario.php), para que
    // siempre quede rastro de por qué cambió.
    $pdo->prepare(
        'UPDATE inventario_productos
         SET nombre = ?, descripcion = ?, unidad = ?, stock_minimo = ?, costo = ?, precio_venta = ?, activo = ?
         WHERE id = ?'
    )->execute([$nombre, $descripcion, $unidad, $stockMinimo, $costo, $precioVenta, $activo, $id]);

    registrarAuditoria('inventario_productos', $id, 'UPDATE', $anterior, [
        'nombre' => $nombre, 'descripcion' => $descripcion, 'unidad' => $unidad,
        'stock_minimo' => $stockMinimo, 'costo' => $costo, 'precio_venta' => $precioVenta, 'activo' => (bool)$activo,
    ]);

    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error al actualizar el producto.']);
}
