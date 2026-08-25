-- Migración 003: identificador legible en el historial (VT-00001 / SIP-0001)
--
-- Objetivo: que la columna "Registro" del historial de cambios muestre el
-- folio de venta o el número de incidencia en vez del id numérico interno,
-- y que el filtro acepte ese mismo valor (VT-xxxx / SIP-xxxx).
--
-- 100% aditiva: solo agrega una columna NULLABLE y su índice. registro_id
-- (el id numérico interno) no se toca, sigue siendo la referencia exacta.
-- Filas ya existentes quedan con registro_folio = NULL (el historial sigue
-- mostrando el id numérico para esas, como hasta ahora).
--
-- Cómo aplicar: igual que las migraciones anteriores, primero en apptest.
-- NO usa "IF NOT EXISTS" (no lo soportan todas las versiones de MySQL);
-- solo debe ejecutarse una vez.

ALTER TABLE `auditoria`
    ADD COLUMN `registro_folio` VARCHAR(50) NULL AFTER `registro_id`,
    ADD INDEX `idx_registro_folio` (`registro_folio`);
