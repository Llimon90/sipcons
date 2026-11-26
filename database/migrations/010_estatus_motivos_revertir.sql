-- Revierte 010_estatus_motivos.sql: quita la columna motivo_estatus de todas las empresas.
-- Los estatus que la migración convirtió (Resuelto, Programado, vacíos...) no se regresan.

DROP PROCEDURE IF EXISTS servidesk_010r;

DELIMITER $$
CREATE PROCEDURE servidesk_010r()
BEGIN
    DECLARE fin INT DEFAULT 0;
    DECLARE eid INT;
    DECLARE c CURSOR FOR SELECT id FROM empresas;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET fin = 1;
    OPEN c;
    ciclo: LOOP
        FETCH c INTO eid;
        IF fin THEN LEAVE ciclo; END IF;
        IF (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = CONCAT('e', eid, '_incidencias') AND COLUMN_NAME = 'motivo_estatus') > 0 THEN
            SET @s = CONCAT('ALTER TABLE `e', eid, '_incidencias` DROP COLUMN motivo_estatus');
            PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
        END IF;
    END LOOP;
    CLOSE c;
END$$
DELIMITER ;

CALL servidesk_010r();
DROP PROCEDURE servidesk_010r;
