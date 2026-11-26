-- Migración 070: extiende el inventario de anoche (migración 031) con lo que
-- el inventario real de Servidesk usa: categoría, marca, modelo y ubicación.
--
-- Alcance acotado a propósito frente al inventario de Servidesk
-- (backend/inventario.php, 436 líneas): aquí NO se portan series por pieza
-- (D-45), congelado de precio por movimiento (D-51), servicios/mano de obra
-- sin existencia (D-23), fotos de producto, ni la conciliación con
-- cotizaciones — son suficientes para justificar su propia ronda. Lo que SÍ
-- se porta: categorías reales y el enganche de consumo/devolución de
-- refacciones en una incidencia (ver consumir-refaccion-incidencia.php /
-- devolver-refaccion-incidencia.php), reutilizando `ajustarStockInventario()`
-- que ya existía.
--
-- Se mantienen los nombres de tabla/columna de anoche (inventario_productos,
-- inventario_movimientos, producto_id, stock_actual, costo) para no romper
-- los endpoints ya fusionados en pruebas (guardar-producto.php,
-- finanzas-resumen.php, etc.) en vez de adoptar los nombres reales de
-- Servidesk (inventario_articulos/articulo_id/existencia/costo_unitario).
--
-- Cómo aplicar: igual que las migraciones anteriores, primero en apptest.

ALTER TABLE `inventario_productos`
  ADD COLUMN `categoria` ENUM('Equipo','Refacción','Consumible','Herramienta') NOT NULL DEFAULT 'Refacción' AFTER `nombre`,
  ADD COLUMN `marca` VARCHAR(100) NULL AFTER `categoria`,
  ADD COLUMN `modelo` VARCHAR(100) NULL AFTER `marca`,
  ADD COLUMN `ubicacion` VARCHAR(100) NULL AFTER `descripcion`;
