-- Revierte 022_incluye_congelado.sql: quita cotizacion_partidas.incluye_congelado de todas las empresas.
SET NAMES utf8mb4;
DROP PROCEDURE IF EXISTS servidesk_022r;
DELIMITER $$
CREATE PROCEDURE servidesk_022r()
BEGIN
    DECLARE fin INT DEFAULT 0;
    DECLARE t VARCHAR(64);
    DECLARE c CURSOR FOR SELECT TABLE_NAME FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'e%\_cotizacion_partidas' AND COLUMN_NAME = 'incluye_congelado';
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET fin = 1;
    OPEN c;
    ciclo: LOOP
        FETCH c INTO t;
        IF fin THEN LEAVE ciclo; END IF;
        SET @s = CONCAT('ALTER TABLE `', t, '` DROP COLUMN incluye_congelado');
        PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
    END LOOP;
    CLOSE c;
END$$
DELIMITER ;
CALL servidesk_022r();
DROP PROCEDURE servidesk_022r;
