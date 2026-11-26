-- Revierte 011_folios_unicos.sql: quita los índices únicos, la columna clave_envio y la
-- tabla folios. Los folios y nombres renombrados no se regresan, y ventas.folio se queda
-- en VARCHAR(20).

DROP PROCEDURE IF EXISTS servidesk_011r;

DELIMITER $$
CREATE PROCEDURE servidesk_011r()
BEGIN
    DECLARE fin INT DEFAULT 0;
    DECLARE eid INT;
    DECLARE c CURSOR FOR SELECT id FROM empresas;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET fin = 1;
    OPEN c;
    ciclo: LOOP
        FETCH c INTO eid;
        IF fin THEN LEAVE ciclo; END IF;
        IF (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = CONCAT('e', eid, '_incidencias') AND INDEX_NAME = 'uniq_numero_incidente') > 0 THEN
            SET @s = CONCAT('ALTER TABLE `e', eid, '_incidencias` DROP INDEX uniq_numero_incidente');
            PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
        END IF;
        IF (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = CONCAT('e', eid, '_incidencias') AND COLUMN_NAME = 'clave_envio') > 0 THEN
            SET @s = CONCAT('ALTER TABLE `e', eid, '_incidencias` DROP COLUMN clave_envio');
            PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
        END IF;
        IF (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = CONCAT('e', eid, '_ventas') AND INDEX_NAME = 'uniq_folio') > 0 THEN
            SET @s = CONCAT('ALTER TABLE `e', eid, '_ventas` DROP INDEX uniq_folio');
            PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
        END IF;
        SET @s = CONCAT('DROP TABLE IF EXISTS `e', eid, '_folios`');
        PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
    END LOOP;
    CLOSE c;
    IF (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'usuarios' AND INDEX_NAME = 'uniq_empresa_nombre') > 0 THEN
        ALTER TABLE usuarios DROP INDEX uniq_empresa_nombre;
    END IF;
END$$
DELIMITER ;

CALL servidesk_011r();
DROP PROCEDURE servidesk_011r;
