-- 020: precio congelado de los conceptos aplicados (D-51, auditoría integral 2026-11-24). Versión 2.21.0.
--
-- En cada empresa (tablas e<ID>_):
--   inventario_movimientos.precio_unitario   precio de venta del artículo el día del movimiento.
--     El cobro de una orden sin cotización (D-49) valúa las refacciones y equipos aplicados
--     con este precio, no con el del catálogo de hoy. Los movimientos anteriores quedan en
--     NULL y se siguen valuando con el precio del catálogo.
-- Se puede ejecutar más de una vez. Sin esta migración el sistema funciona como antes.
SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS servidesk_020_columna;
DROP PROCEDURE IF EXISTS servidesk_020_empresa;
DROP PROCEDURE IF EXISTS servidesk_020;

DELIMITER $$
CREATE PROCEDURE servidesk_020_columna(IN p VARCHAR(20), IN tabla VARCHAR(64), IN columna VARCHAR(64), IN definicion TEXT)
BEGIN
    IF (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = CONCAT(p, tabla) AND COLUMN_NAME = columna) = 0 THEN
        SET @s = CONCAT('ALTER TABLE `', p, tabla, '` ADD COLUMN ', columna, ' ', definicion);
        PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
    END IF;
END$$

CREATE PROCEDURE servidesk_020_empresa(IN p VARCHAR(20))
BEGIN
    CALL servidesk_020_columna(p, 'inventario_movimientos', 'precio_unitario', 'DECIMAL(12,2) DEFAULT NULL COMMENT ''Precio de venta del día del movimiento (D-51)'' AFTER costo_unitario');
END$$

CREATE PROCEDURE servidesk_020()
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
            AND TABLE_NAME = CONCAT('e', eid, '_inventario_movimientos')) > 0 THEN
            CALL servidesk_020_empresa(CONCAT('e', eid, '_'));
        END IF;
    END LOOP;
    CLOSE c;
END$$
DELIMITER ;

CALL servidesk_020();
DROP PROCEDURE servidesk_020;
DROP PROCEDURE servidesk_020_empresa;
DROP PROCEDURE servidesk_020_columna;
