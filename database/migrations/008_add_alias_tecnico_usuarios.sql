-- Migración 008: alias de técnico en usuarios
--
-- Objetivo: el dashboard personal del técnico (public/dashboard-tecnico.html)
-- filtra sus incidencias comparando el nombre de su sesión (usuarios.nombre)
-- contra el texto libre del campo incidencias.tecnico (que viene de un
-- selector histórico, no de una relación con usuarios). En el caso normal
-- ambos coinciden. `alias_tecnico` es una válvula de escape opcional: si
-- para alguien no coinciden (typo, apodo, acento distinto, etc.), un admin
-- puede llenar este campo con el texto exacto que sí aparece en incidencias
-- y el sistema lo usa en vez de `nombre` para ese usuario, sin tocar ni
-- migrar ninguna incidencia existente.
--
-- 100% aditiva: solo agrega una columna nueva, nula por defecto (no cambia
-- el comportamiento de nadie hasta que un admin la llene a propósito).
--
-- Cómo aplicar: igual que las migraciones anteriores, primero en apptest.
-- NO usa "IF NOT EXISTS" (no lo soportan todas las versiones de MySQL);
-- solo debe ejecutarse una vez.

ALTER TABLE `usuarios`
    ADD COLUMN `alias_tecnico` VARCHAR(150) NULL DEFAULT NULL
        COMMENT 'Nombre exacto tal como aparece en incidencias.tecnico, si difiere de usuarios.nombre'
        AFTER `nombre`;
