-- Migración 017: vínculo real incidencias -> clientes (cliente_id)
--
-- `incidencias.cliente` siempre fue texto libre (lo que se escribió en el
-- formulario), sin relación formal con `clientes.id`. El portal de
-- autoservicio necesita saber con certeza de qué cliente es cada ticket, así
-- que se agrega `cliente_id` y se rellena por coincidencia de nombre
-- (aproximado: mayúsculas/espacios ignorados). Lo que no calce queda en
-- NULL y se vincula manualmente desde Clientes → Acceso al portal
-- (backend/vincular-cliente-incidencia.php); una incidencia sin cliente_id
-- simplemente no aparece en el portal todavía, no se pierde ni se bloquea
-- en el uso normal del personal.
--
-- Cómo aplicar: igual que las migraciones anteriores, primero en apptest.

ALTER TABLE `incidencias`
  ADD COLUMN `cliente_id` INT UNSIGNED NULL AFTER `cliente`,
  ADD INDEX `idx_incidencias_cliente_id` (`cliente_id`);

UPDATE `incidencias` i
JOIN `clientes` c ON TRIM(LOWER(i.cliente)) = TRIM(LOWER(c.nombre))
SET i.cliente_id = c.id
WHERE i.cliente_id IS NULL;
