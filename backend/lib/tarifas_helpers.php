<?php
// Tarifas de servicio de la empresa (D-43), editables en Mi empresa.
//  - Mano de obra: tiempo. Un concepto del catálogo, "Mano de obra (por hora)", con unidad
//    "hr"; su precio es la tarifa por hora de la empresa (marca.tarifa_mano_obra).
//  - Servicios: precio cerrado. Artículos de categoría "Servicio"; lo que incluyen se guarda
//    en inventario_articulos.descripcion y se muestra en la cotización.
require_once __DIR__ . '/../../config/empresa.php';

const TAR_SKU_MANO_OBRA = 'MO-HORA';
const TAR_NOMBRE_MANO_OBRA = 'Mano de obra (por hora)';

function tarObtener(PDO $pdo, array $empresa): array {
    $st = $pdo->query("SELECT id, nombre, precio_venta AS precio, COALESCE(descripcion, '') AS incluye
                       FROM inventario_articulos WHERE categoria = 'Servicio' AND activo = 1 ORDER BY nombre");
    return [
        'tarifa_hora' => (float)($empresa['marca']['tarifa_mano_obra'] ?? 0),
        'servicios' => array_map(fn($f) => ['id' => (int)$f['id'], 'nombre' => $f['nombre'], 'precio' => (float)$f['precio'], 'incluye' => $f['incluye']],
            $st->fetchAll(PDO::FETCH_ASSOC)),
    ];
}

/** SKU libre para un servicio nuevo (SRV-0001, SRV-0002...). */
function tarSkuServicio(PDO $pdo): string {
    $n = (int)$pdo->query("SELECT COUNT(*) FROM inventario_articulos WHERE sku LIKE 'SRV-%'")->fetchColumn();
    $existe = $pdo->prepare('SELECT 1 FROM inventario_articulos WHERE sku = ?');
    do {
        $sku = sprintf('SRV-%04d', ++$n);
        $existe->execute([$sku]);
    } while ($existe->fetchColumn());
    return $sku;
}

/**
 * Guarda la tarifa por hora y la lista de servicios. Un servicio sin id se crea; uno que
 * ya no viene en la lista se desactiva (no se borra: puede estar en órdenes y cotizaciones).
 * Devuelve [antes, después] para el historial.
 */
function tarGuardar(PDO $pdo, array $empresa, array $body): array {
    $tarifa = round((float)($body['tarifa_hora'] ?? -1), 2);
    if ($tarifa < 0 || $tarifa > 1000000) throw new InvalidArgumentException('Escribe la tarifa por hora de la mano de obra (0 o más).');
    $servicios = [];
    foreach ((array)($body['servicios'] ?? []) as $s) {
        $nombre = trim((string)($s['nombre'] ?? ''));
        if ($nombre === '') continue;
        $precio = round((float)($s['precio'] ?? -1), 2);
        if ($precio < 0) throw new InvalidArgumentException("Escribe el precio del servicio \"$nombre\".");
        $clave = mb_strtolower($nombre);
        if (isset($servicios[$clave])) throw new InvalidArgumentException("El servicio \"$nombre\" está repetido.");
        $servicios[$clave] = ['id' => (int)($s['id'] ?? 0), 'nombre' => mb_substr($nombre, 0, 150), 'precio' => $precio,
            'incluye' => mb_substr(trim((string)($s['incluye'] ?? '')), 0, 2000)];
    }

    $antes = tarObtener($pdo, $empresa);
    $pdo->beginTransaction();
    try {
        $marca = $empresa['marca'];
        $marca['tarifa_mano_obra'] = $tarifa;
        $pdo->prepare('UPDATE empresas SET marca = ?, actualizado_en = NOW() WHERE id = ?')
            ->execute([json_encode($marca, JSON_UNESCAPED_UNICODE), (int)$empresa['id']]);

        $st = $pdo->prepare('SELECT id FROM inventario_articulos WHERE sku = ?');
        $st->execute([TAR_SKU_MANO_OBRA]);
        $mo = (int)$st->fetchColumn();
        if ($mo) {
            $pdo->prepare("UPDATE inventario_articulos SET precio_venta = ?, unidad = 'hr', categoria = 'Mano de obra', controla_existencia = 0, activo = 1, actualizado_en = NOW() WHERE id = ?")
                ->execute([$tarifa, $mo]);
        } else {
            $pdo->prepare("INSERT INTO inventario_articulos (sku, nombre, categoria, controla_existencia, unidad, precio_venta, descripcion)
                           VALUES (?, ?, 'Mano de obra', 0, 'hr', ?, 'Se cobra por hora; admite fracciones (1.5 h).')")
                ->execute([TAR_SKU_MANO_OBRA, TAR_NOMBRE_MANO_OBRA, $tarifa]);
        }

        $conservar = [];
        $upd = $pdo->prepare("UPDATE inventario_articulos SET nombre = ?, precio_venta = ?, descripcion = ?, activo = 1, actualizado_en = NOW()
                              WHERE id = ? AND categoria = 'Servicio'");
        $ins = $pdo->prepare("INSERT INTO inventario_articulos (sku, nombre, categoria, controla_existencia, unidad, precio_venta, descripcion)
                              VALUES (?, ?, 'Servicio', 0, 'servicio', ?, ?)");
        foreach ($servicios as $s) {
            if ($s['id']) {
                $upd->execute([$s['nombre'], $s['precio'], $s['incluye'] ?: null, $s['id']]);
                if ($upd->rowCount() === 0) {
                    $chk = $pdo->prepare("SELECT 1 FROM inventario_articulos WHERE id = ? AND categoria = 'Servicio'");
                    $chk->execute([$s['id']]);
                    if (!$chk->fetchColumn()) throw new InvalidArgumentException("El servicio \"{$s['nombre']}\" ya no existe. Recarga la página.");
                }
                $conservar[] = $s['id'];
            } else {
                $ins->execute([tarSkuServicio($pdo), $s['nombre'], $s['precio'], $s['incluye'] ?: null]);
                $conservar[] = (int)$pdo->lastInsertId();
            }
        }
        $sql = "UPDATE inventario_articulos SET activo = 0, actualizado_en = NOW() WHERE categoria = 'Servicio' AND activo = 1";
        if ($conservar) $sql .= ' AND id NOT IN (' . implode(',', array_map('intval', $conservar)) . ')';
        $pdo->exec($sql);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    EmpresaContexto::$fila = null;  // la marca cambió
    return [$antes, tarObtener($pdo, empresaActual() ?? $empresa)];
}
