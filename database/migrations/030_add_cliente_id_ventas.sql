-- Migración 030: cliente_id en ventas
--
-- Objetivo: poder ligar una venta a su fila real de `clientes` (hoy
-- `ventas.cliente` es solo texto, igual que en incidencias) para que el panel
-- de finanzas y las cuentas por cobrar puedan agrupar por cliente real sin
-- depender de comparar nombres en cada consulta.
--
-- 100% aditiva. Se llena por coincidencia aproximada de nombre (TRIM/LOWER)
-- con la tabla `clientes`; las ventas que no calcen quedan con cliente_id NULL.
-- `backend/registro_ventas.php` ya se actualizó para buscar y guardar este
-- cliente_id en cada venta nueva.
--
-- Cómo aplicar: igual que las migraciones anteriores, primero en apptest.

ALTER TABLE `ventas`
  ADD COLUMN `cliente_id` INT NULL AFTER `cliente`,
  ADD INDEX `idx_ventas_cliente_id` (`cliente_id`);

UPDATE `ventas` v
  JOIN `clientes` c ON TRIM(LOWER(v.cliente)) = TRIM(LOWER(c.nombre))
  SET v.cliente_id = c.id
  WHERE v.cliente_id IS NULL;
