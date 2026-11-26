-- Revierte 020_precio_congelado.sql: quita inventario_movimientos.precio_unitario de todas las empresas.
SET NAMES utf8mb4;
DROP PROCEDURE IF EXISTS servidesk_020r;
DELIMITER $$
CREATE PROCEDURE servidesk_020r()
BEGIN
    DECLARE fin INT DEFAULT 0;
    DECLARE t VARCHAR(64);
    DECLARE c CURSOR FOR SELECT TABLE_NAME FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'e%\_inventario_movimientos' AND COLUMN_NAME = 'precio_unitario';
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET fin = 1;
    OPEN c;
    ciclo: LOOP
        FETCH c INTO t;
        IF fin THEN LEAVE ciclo; END IF;
        SET @s = CONCAT('ALTER TABLE `', t, '` DROP COLUMN precio_unitario');
        PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
    END LOOP;
    CLOSE c;
END$$
DELIMITER ;
CALL servidesk_020r();
DROP PROCEDURE servidesk_020r;
