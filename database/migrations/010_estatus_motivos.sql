-- 010: Estatus de las órdenes de servicio (versión 2.13, docs/REGLAS_NEGOCIO.md 0.1 y D-37).
--
-- En cada empresa (tabla e<ID>_incidencias):
--   motivo_estatus  motivo del estatus actual: por qué está Pendiente o por qué se canceló.
--                   Se borra al salir de ese estatus (el historial conserva los anteriores).
--   Estatus heredados -> lista cerrada de 8 estatus:
--     Abierto, Asignado, En proceso, Pendiente, Completado,
--     Cerrado sin factura, Cerrado con factura, Cancelado
--     · mayúsculas/minúsculas o espacios distintos -> forma oficial
--     · "Resuelto" -> Completado
--     · vacío, "Programado" o cualquier otro texto -> Abierto
--     · Abierto con técnico -> Asignado; Asignado/En proceso sin técnico -> Abierto (D-5)
--     · Pendiente sin motivo -> "Otro: sin motivo registrado (antes de la versión 2.13)"
--
-- Se puede ejecutar más de una vez. Para quitar la columna: 010_estatus_motivos_revertir.sql
-- (los estatus convertidos no se regresan a su texto anterior).

DROP PROCEDURE IF EXISTS servidesk_010_empresa;
DROP PROCEDURE IF EXISTS servidesk_010;

DELIMITER $$
CREATE PROCEDURE servidesk_010_empresa(IN p VARCHAR(20))
BEGIN
    IF (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = CONCAT(p, 'incidencias') AND COLUMN_NAME = 'motivo_estatus') = 0 THEN
        SET @s = CONCAT('ALTER TABLE `', p, 'incidencias` ADD COLUMN motivo_estatus VARCHAR(255) DEFAULT NULL ',
            'COMMENT ''Motivo del estatus actual (Pendiente o Cancelado)'' AFTER estatus');
        PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
    END IF;

    SET @s = CONCAT('UPDATE `', p, 'incidencias` SET estatus = CASE LOWER(TRIM(COALESCE(estatus, ''''))) ',
        'WHEN ''abierto'' THEN ''Abierto'' ',
        'WHEN ''asignado'' THEN ''Asignado'' ',
        'WHEN ''en proceso'' THEN ''En proceso'' ',
        'WHEN ''pendiente'' THEN ''Pendiente'' ',
        'WHEN ''completado'' THEN ''Completado'' ',
        'WHEN ''resuelto'' THEN ''Completado'' ',
        'WHEN ''cerrado sin factura'' THEN ''Cerrado sin factura'' ',
        'WHEN ''cerrado con factura'' THEN ''Cerrado con factura'' ',
        'WHEN ''cancelado'' THEN ''Cancelado'' ',
        'ELSE ''Abierto'' END');
    PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

    SET @s = CONCAT('UPDATE `', p, 'incidencias` SET estatus = ''Asignado'' ',
        'WHERE estatus = ''Abierto'' AND TRIM(COALESCE(tecnico, '''')) <> ''''');
    PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

    SET @s = CONCAT('UPDATE `', p, 'incidencias` SET estatus = ''Abierto'' ',
        'WHERE estatus IN (''Asignado'', ''En proceso'') AND TRIM(COALESCE(tecnico, '''')) = ''''');
    PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

    SET @s = CONCAT('UPDATE `', p, 'incidencias` SET motivo_estatus = ',
        '''Otro: sin motivo registrado (antes de la versión 2.13)'' ',
        'WHERE estatus IN (''Pendiente'', ''Cancelado'') AND TRIM(COALESCE(motivo_estatus, '''')) = ''''');
    PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

    SET @s = CONCAT('UPDATE `', p, 'incidencias` SET motivo_estatus = NULL ',
        'WHERE estatus NOT IN (''Pendiente'', ''Cancelado'') AND motivo_estatus IS NOT NULL');
    PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
END$$

CREATE PROCEDURE servidesk_010()
BEGIN
    DECLARE fin INT DEFAULT 0;
    DECLARE eid INT;
    DECLARE c CURSOR FOR SELECT id FROM empresas;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET fin = 1;
    OPEN c;
    ciclo: LOOP
        FETCH c INTO eid;
        IF fin THEN LEAVE ciclo; END IF;
        -- Solo empresas cuyas tablas ya existen
        IF (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = CONCAT('e', eid, '_incidencias')) > 0 THEN
            CALL servidesk_010_empresa(CONCAT('e', eid, '_'));
        END IF;
    END LOOP;
    CLOSE c;
END$$
DELIMITER ;

CALL servidesk_010();
DROP PROCEDURE servidesk_010;
DROP PROCEDURE servidesk_010_empresa;
