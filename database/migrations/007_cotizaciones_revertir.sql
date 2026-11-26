-- Deshace 007_cotizaciones.sql. ¡BORRA todas las cotizaciones de todas las
-- empresas! Respalda la base antes de ejecutarlo. Los artículos de tipo
-- Servicio o Mano de obra se quedan en el inventario (con existencia 0).

DROP PROCEDURE IF EXISTS servidesk_007_revertir;

DELIMITER $$
CREATE PROCEDURE servidesk_007_revertir()
BEGIN
    DECLARE fin INT DEFAULT 0;
    DECLARE eid INT;
    DECLARE c CURSOR FOR SELECT id FROM empresas;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET fin = 1;
    OPEN c;
    ciclo: LOOP
        FETCH c INTO eid;
        IF fin THEN LEAVE ciclo; END IF;
        SET @s = CONCAT('DROP TABLE IF EXISTS `e', eid, '_cotizacion_ventas`, `e', eid, '_cotizacion_partidas`, `e', eid, '_cotizaciones`');
        PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
        IF (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = CONCAT('e', eid, '_permisos_rol')) > 0 THEN
            SET @s = CONCAT('DELETE FROM `e', eid, '_permisos_rol` WHERE modulo = ''cotizaciones''');
            PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
        END IF;
        IF (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = CONCAT('e', eid, '_inventario_articulos') AND COLUMN_NAME = 'controla_existencia') > 0 THEN
            SET @s = CONCAT('ALTER TABLE `e', eid, '_inventario_articulos` DROP COLUMN controla_existencia');
            PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
        END IF;
    END LOOP;
    CLOSE c;
END$$
DELIMITER ;

CALL servidesk_007_revertir();
DROP PROCEDURE servidesk_007_revertir;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'puede_autorizar_descuentos') > 0,
    'ALTER TABLE usuarios DROP COLUMN puede_autorizar_descuentos', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
