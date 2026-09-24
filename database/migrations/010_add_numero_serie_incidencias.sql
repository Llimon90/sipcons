-- Migración 010: número de serie del equipo en incidencias
--
-- Objetivo: poder medir reincidencias reales. Por temas de facturación las
-- incidencias no se reabren: si un equipo vuelve a fallar de lo mismo, el
-- cliente genera otro ticket. La única forma de detectarlo es saber a qué
-- equipo (número de serie) corresponde cada incidencia. El campo es
-- opcional y se puede elegir del padrón de equipos del cliente.
--
-- 100% aditiva: solo agrega una columna nula por defecto y un índice; no
-- cambia el comportamiento de nada existente. Mientras la columna no exista,
-- el sistema sigue funcionando y las reincidencias simplemente aparecen en 0.
--
-- Cómo aplicar: primero en apptest. Debe ejecutarse una sola vez.

ALTER TABLE `incidencias`
    ADD COLUMN `numero_serie` VARCHAR(100) NULL DEFAULT NULL
        COMMENT 'Número de serie del equipo atendido (para detectar reincidencias)'
        AFTER `equipo`,
    ADD INDEX `idx_incidencias_numero_serie` (`numero_serie`);
