<?php
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../auth/permisos.php';
require_once __DIR__ . '/../auth/audit.php';
requirePermiso('cotizaciones');
header('Content-Type: application/json');

try {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];

    $clienteId = (int)($data['cliente_id'] ?? 0);
    $sucursal = trim((string)($data['sucursal'] ?? ''));
    $fecha = !empty($data['fecha']) ? (string)$data['fecha'] : date('Y-m-d');
    $fechaVigencia = !empty($data['fecha_vigencia']) ? (string)$data['fecha_vigencia'] : null;
    $notas = trim((string)($data['notas'] ?? ''));
    $lineas = is_array($data['detalles'] ?? null) ? $data['detalles'] : [];

    if ($clienteId <= 0 || count($lineas) === 0) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Elige un cliente y agrega al menos una línea.']);
        exit;
    }

    $pdo->beginTransaction();

    $cliente = $pdo->prepare('SELECT nombre FROM clientes WHERE id = ?');
    $cliente->execute([$clienteId]);
    $nombreCliente = $cliente->fetchColumn();
    if (!$nombreCliente) {
        throw new RuntimeException('El cliente no existe.');
    }

    // Folio: mismo estilo que ventas (VT-#####), consultado dentro de la
    // transacción para minimizar (no eliminar) la ventana de dos folios
    // iguales, igual que ya hace backend/registro_ventas.php.
    $ultimo = $pdo->query("SELECT folio FROM cotizaciones ORDER BY id DESC LIMIT 1")->fetchColumn();
    $folio = 'COT-' . str_pad($ultimo ? (int)substr($ultimo, 4) + 1 : 1, 5, '0', STR_PAD_LEFT);

    $subtotal = 0;
    $lineasValidadas = [];
    foreach ($lineas as $linea) {
        $descripcion = trim((string)($linea['descripcion'] ?? ''));
        $cantidad = (float)($linea['cantidad'] ?? 0);
        $precioUnitario = (float)($linea['precio_unitario'] ?? 0);
        $productoId = !empty($linea['producto_id']) ? (int)$linea['producto_id'] : null;

        if ($descripcion === '' || $cantidad <= 0 || $precioUnitario < 0) {
            throw new RuntimeException('Cada línea necesita descripción, cantidad y precio válidos.');
        }
        $importe = round($cantidad * $precioUnitario, 2);
        $subtotal += $importe;
        $lineasValidadas[] = compact('descripcion', 'cantidad', 'precioUnitario', 'productoId', 'importe');
    }
    $subtotal = round($subtotal, 2);
    $iva = round($subtotal * 0.16, 2);
    $total = round($subtotal + $iva, 2);

    $pdo->prepare(
        'INSERT INTO cotizaciones (folio, cliente_id, cliente, sucursal, fecha, fecha_vigencia, subtotal, iva, total, notas, creado_por)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $folio, $clienteId, $nombreCliente, $sucursal !== '' ? $sucursal : null, $fecha, $fechaVigencia,
        $subtotal, $iva, $total, $notas !== '' ? $notas : null,
        $_SESSION['nombre'] ?? ($_SESSION['usuario'] ?? null),
    ]);
    $cotizacionId = (int)$pdo->lastInsertId();

    $stmtDetalle = $pdo->prepare(
        'INSERT INTO cotizacion_detalles (cotizacion_id, producto_id, descripcion, cantidad, precio_unitario, importe)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    foreach ($lineasValidadas as $l) {
        $stmtDetalle->execute([$cotizacionId, $l['productoId'], $l['descripcion'], $l['cantidad'], $l['precioUnitario'], $l['importe']]);
    }

    $pdo->commit();

    registrarAuditoria('cotizaciones', $cotizacionId, 'CREATE', null, [
        'cliente' => $nombreCliente, 'total' => $total, 'lineas' => count($lineasValidadas),
    ], $folio);

    echo json_encode(['success' => true, 'id' => $cotizacionId, 'folio' => $folio, 'total' => $total]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $esValidacion = $e instanceof RuntimeException;
    http_response_code($esValidacion ? 422 : 500);
    echo json_encode(['success' => false, 'message' => $esValidacion ? $e->getMessage() : 'Error al guardar la cotización.']);
}
