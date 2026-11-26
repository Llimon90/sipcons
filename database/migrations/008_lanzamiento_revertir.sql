-- Deshace 008_lanzamiento.sql. Las notas de crédito y abonos registrados se
-- quedan en pagos como pagos normales (su monto no cambia). Los planes
-- conservan permite_marca = 1 (ajústalo desde el panel de plataforma si hace
-- falta). Las fotos de productos se quedan en uploads/ pero dejan de ligarse.

DROP TABLE IF EXISTS `login_intentos`;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pagos' AND COLUMN_NAME = 'tipo') > 0,
    'ALTER TABLE pagos DROP COLUMN tipo', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

DROP PROCEDURE IF EXISTS servidesk_008_revertir;

DELIMITER $$
CREATE PROCEDURE servidesk_008_revertir()
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
            AND TABLE_NAME = CONCAT('e', eid, '_inventario_articulos') AND COLUMN_NAME = 'imagen') > 0 THEN
            SET @s = CONCAT('ALTER TABLE `e', eid, '_inventario_articulos` DROP COLUMN imagen');
            PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
        END IF;
    END LOOP;
    CLOSE c;
END$$
DELIMITER ;

CALL servidesk_008_revertir();
DROP PROCEDURE servidesk_008_revertir;
