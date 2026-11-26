-- Deshace 009_finanzas.sql. ¡BORRA todas las cuentas por cobrar, cobros y
-- gastos de todas las empresas! Respalda la base antes de ejecutarlo.

DROP PROCEDURE IF EXISTS servidesk_009_revertir;

DELIMITER $$
CREATE PROCEDURE servidesk_009_revertir()
BEGIN
    DECLARE fin INT DEFAULT 0;
    DECLARE eid INT;
    DECLARE c CURSOR FOR SELECT id FROM empresas;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET fin = 1;
    OPEN c;
    ciclo: LOOP
        FETCH c INTO eid;
        IF fin THEN LEAVE ciclo; END IF;
        SET @s = CONCAT('DROP TABLE IF EXISTS `e', eid, '_cobros`, `e', eid, '_cuentas_cobrar`, `e', eid, '_gastos`');
        PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
        IF (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = CONCAT('e', eid, '_permisos_rol')) > 0 THEN
            SET @s = CONCAT('DELETE FROM `e', eid, '_permisos_rol` WHERE modulo = ''finanzas''');
            PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
        END IF;
        IF (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = CONCAT('e', eid, '_clientes') AND COLUMN_NAME = 'dias_credito') > 0 THEN
            SET @s = CONCAT('ALTER TABLE `e', eid, '_clientes` DROP COLUMN dias_credito');
            PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
        END IF;
    END LOOP;
    CLOSE c;
END$$
DELIMITER ;

CALL servidesk_009_revertir();
DROP PROCEDURE servidesk_009_revertir;
