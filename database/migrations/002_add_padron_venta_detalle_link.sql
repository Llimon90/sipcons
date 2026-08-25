-- Migración 002: enlace exacto entre padron_equipos y venta_detalles
--
-- Objetivo: permitir emparejar cada fila del Padrón de Equipos con la fila
-- exacta de venta_detalles de la que proviene, en vez de adivinar por
-- numero_serie. Es necesario porque equipos sin serie real (S/N, N/A o
-- vacío) pueden repetirse dentro de una misma venta, y emparejar solo por
-- numero_serie podría confundir dos equipos distintos.
--
-- 100% aditiva: solo agrega una columna NULLABLE y su índice. No modifica
-- ni borra columnas existentes. Las filas ya existentes quedan con
-- venta_detalle_id = NULL (el código sigue funcionando igual para ellas,
-- solo no se benefician de este emparejamiento exacto en ediciones futuras).
--
-- Cómo aplicar: igual que la migración 001, primero en apptest, luego en
-- producción cuando se confirme que todo funciona.
--
-- Nota: a propósito NO usa "IF NOT EXISTS" en el ALTER TABLE (esa sintaxis
-- solo la soportan versiones recientes de MySQL/MariaDB). Solo debe
-- ejecutarse una vez; si por error se corre dos veces, MySQL devolverá un
-- error de "columna duplicada" que no daña nada (puedes verificar antes con
-- `SHOW COLUMNS FROM padron_equipos LIKE 'venta_detalle_id';`).

ALTER TABLE `padron_equipos`
    ADD COLUMN `venta_detalle_id` INT NULL AFTER `venta_id`,
    ADD INDEX `idx_venta_detalle` (`venta_detalle_id`);
