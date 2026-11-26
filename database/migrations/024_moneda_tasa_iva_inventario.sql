-- 024: moneda y tasa de IVA por artículo de inventario (2.24.0).
--
-- En cada empresa (tablas e<ID>_inventario_articulos):
--   moneda    CHAR(3) NOT NULL DEFAULT 'MXN'
--             Moneda en que está expresado el precio de venta (MXN o USD).
--             Al cargar el artículo en una cotización o OS se convierte a la
--             moneda del documento usando su tipo_cambio.
--   tasa_iva  DECIMAL(4,2) NOT NULL DEFAULT 0.16
--             Tasa de IVA del artículo (0.16, 0.08, 0.00…). Al cargar el
--             artículo como línea nueva en una cotización, esta tasa se usa
--             como valor por defecto en lugar de la tasa general de la empresa.
--             En el cobro directo de una OS sin cotización (finConceptosDeOrden),
--             el IVA se calcula por artículo usando esta tasa.
-- Se puede ejecutar más de una vez. Sin esta migración el sistema funciona como antes.
SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS servidesk_024_columna;
DROP PROCEDURE IF EXISTS servidesk_024_empresa;
DROP PROCEDURE IF EXISTS servidesk_024;

DELIMITER $$
CREATE PROCEDURE servidesk_024_columna(IN p VARCHAR(20), IN tabla VARCHAR(64), IN columna VARCHAR(64), IN definicion TEXT)
BEGIN
    IF (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = CONCAT(p, tabla) AND COLUMN_NAME = columna) = 0 THEN
        SET @s = CONCAT('ALTER TABLE `', p, tabla, '` ADD COLUMN ', columna, ' ', definicion);
        PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
    END IF;
END$$

CREATE PROCEDURE servidesk_024_empresa(IN p VARCHAR(20))
BEGIN
    CALL servidesk_024_columna(p, 'inventario_articulos', 'moneda',
        "CHAR(3) NOT NULL DEFAULT 'MXN' COMMENT 'Moneda del precio de venta (MXN|USD)' AFTER precio_venta");
    CALL servidesk_024_columna(p, 'inventario_articulos', 'tasa_iva',
        "DECIMAL(4,2) NOT NULL DEFAULT 0.16 COMMENT 'Tasa de IVA del artículo (0.16, 0.08, 0.00)' AFTER moneda");
END$$

CREATE PROCEDURE servidesk_024()
BEGIN
    DECLARE fin INT DEFAULT 0;
    DECLARE eid INT;
    DECLARE c CURSOR FOR SELECT id FROM empresas;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET fin = 1;
    OPEN c;
    ciclo: LOOP
        FETCH c INTO eid;
        IF fin THEN LEAVE ciclo; END IF;
        IF (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = CONCAT('e', eid, '_inventario_articulos')) > 0 THEN
            CALL servidesk_024_empresa(CONCAT('e', eid, '_'));
        END IF;
    END LOOP;
    CLOSE c;
END$$
DELIMITER ;

CALL servidesk_024();
DROP PROCEDURE servidesk_024;
DROP PROCEDURE servidesk_024_empresa;
DROP PROCEDURE servidesk_024_columna;
