-- Migración 013: nombres claros para dos columnas de incidencias
--
--   numero -> reporte_cliente  (el # de reporte/incidencia que da el cliente, ej. ST-66477)
--   equipo -> categoria        (Mr. Tienda/Mr. Chef, Calimax, Recolección, Otros...)
--
-- Solo cambia el nombre: tipo, longitud, valor por defecto, índices y datos
-- se conservan. OJO: NO es compatible hacia atrás. El código desplegado junto
-- con esta migración ya usa los nombres nuevos, así que migración y código
-- deben aplicarse juntos (primero en apptest). Las columnas `equipo` de
-- padron_equipos y venta_detalles NO se tocan.
--
-- Requiere MySQL 8.0+ o MariaDB 10.5.2+ (RENAME COLUMN). Para saber la
-- versión: SELECT VERSION();
-- Si es más antigua, usa la alternativa con CHANGE COLUMN del final.
--
-- Cómo aplicar: primero en apptest. Debe ejecutarse una sola vez.

ALTER TABLE `incidencias`
    RENAME COLUMN `numero` TO `reporte_cliente`,
    RENAME COLUMN `equipo` TO `categoria`;

-- ---------------------------------------------------------------------------
-- Alternativa para MySQL 5.7 / MariaDB < 10.5.2
-- CHANGE COLUMN exige repetir la definición completa; cópiala EXACTA de
-- SHOW CREATE TABLE incidencias; (tipo, NULL/NOT NULL, DEFAULT, COMMENT).
-- Ejemplo suponiendo VARCHAR(100) NULL en ambas:
--
-- ALTER TABLE `incidencias`
--     CHANGE COLUMN `numero` `reporte_cliente` VARCHAR(100) NULL DEFAULT NULL,
--     CHANGE COLUMN `equipo` `categoria`       VARCHAR(100) NULL DEFAULT NULL;
-- ---------------------------------------------------------------------------

-- Reversa (si hay que volver al código anterior):
-- ALTER TABLE `incidencias`
--     RENAME COLUMN `reporte_cliente` TO `numero`,
--     RENAME COLUMN `categoria` TO `equipo`;
