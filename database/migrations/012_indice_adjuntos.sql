-- 012: índice en archivos_incidencias.incidencia_id (versión 2.13.3).
-- Los adjuntos de una orden se buscan por su incidencia_id (detalle, eliminar la
-- orden, permisos de archivo); sin índice, MySQL recorre toda la tabla.
-- Opcional y sin efecto en los datos. Se puede ejecutar más de una vez.

DROP PROCEDURE IF EXISTS servidesk_012;

DELIMITER $$
CREATE PROCEDURE servidesk_012()
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
            AND TABLE_NAME = CONCAT('e', eid, '_archivos_incidencias')) > 0
           AND (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = CONCAT('e', eid, '_archivos_incidencias') AND INDEX_NAME = 'idx_incidencia') = 0 THEN
            SET @s = CONCAT('ALTER TABLE `e', eid, '_archivos_incidencias` ADD KEY idx_incidencia (incidencia_id)');
            PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
        END IF;
    END LOOP;
    CLOSE c;
END$$
DELIMITER ;

CALL servidesk_012();
DROP PROCEDURE servidesk_012;
