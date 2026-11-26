-- Deshace 006_roles.sql: devuelve a cada usuario el rol que tenía (según la
-- constancia que dejó la migración en el historial de su empresa) y restaura
-- los privilegios de Técnico/Administrador copiando los del Administrador.
-- Después de revertir, el código vigente seguirá tratando esos roles como
-- Administrador hasta volver a la versión anterior del sistema.

DROP PROCEDURE IF EXISTS servidesk_006_revertir;

DELIMITER $$
CREATE PROCEDURE servidesk_006_revertir()
BEGIN
    DECLARE fin INT DEFAULT 0;
    DECLARE eid INT;
    DECLARE c CURSOR FOR SELECT id FROM empresas;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET fin = 1;

    OPEN c;
    ciclo: LOOP
        FETCH c INTO eid;
        IF fin THEN LEAVE ciclo; END IF;

        IF (SELECT COUNT(*) FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = CONCAT('e', eid, '_auditoria')) > 0 THEN
            SET @s = CONCAT(
                'UPDATE usuarios u JOIN `e', eid, '_auditoria` a ON a.registro_id = u.id AND a.tabla = ''usuarios'' ',
                'AND a.usuario_nombre = ''Migración 006'' ',
                'SET u.rol = JSON_UNQUOTE(JSON_EXTRACT(a.datos_anteriores, ''$.rol'')) ',
                'WHERE u.empresa_id = ', eid, ' AND u.rol = ''Administrador''');
            PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
            SET @s = CONCAT('DELETE FROM `e', eid, '_auditoria` WHERE usuario_nombre = ''Migración 006''');
            PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
        END IF;

        IF (SELECT COUNT(*) FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = CONCAT('e', eid, '_permisos_rol')) > 0 THEN
            SET @s = CONCAT('INSERT IGNORE INTO `e', eid, '_permisos_rol` (rol, modulo, permitido) ',
                'SELECT ''Técnico/Administrador'', modulo, permitido FROM `e', eid, '_permisos_rol` WHERE rol = ''Administrador''');
            PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
        END IF;
    END LOOP;
    CLOSE c;
END$$
DELIMITER ;

CALL servidesk_006_revertir();
DROP PROCEDURE servidesk_006_revertir;
