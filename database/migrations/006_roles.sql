-- 006: Roles simplificados.
--
-- Quedan cuatro roles: Administrador, Supervisor, Administrativo y Técnico.
--   - Técnico/Administrador -> Administrador (el Administrador ya puede
--     recibir incidencias asignadas).
--   - Programador -> Administrador. El acceso total interno ahora se llama
--     "Soporte de plataforma" y solo lo usa la cuenta de plataforma en modo
--     soporte; no se asigna a usuarios.
--
-- Para cada empresa: deja constancia del cambio en su historial (auditoria)
-- y quita los privilegios de los roles que ya no existen.
-- Se puede ejecutar más de una vez. Para deshacerlo: 006_roles_revertir.sql.
-- El código ya normaliza esos roles al iniciar sesión, así que funciona igual
-- antes y después de aplicar esta migración.

DROP PROCEDURE IF EXISTS servidesk_006_roles;

DELIMITER $$
CREATE PROCEDURE servidesk_006_roles()
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
                'INSERT INTO `e', eid, '_auditoria` (usuario_nombre, usuario_rol, accion, tabla, registro_id, datos_anteriores, datos_nuevos) ',
                'SELECT ''Migración 006'', ''Sistema'', ''UPDATE'', ''usuarios'', id, JSON_OBJECT(''rol'', rol), JSON_OBJECT(''rol'', ''Administrador'') ',
                'FROM usuarios WHERE empresa_id = ', eid, ' AND rol IN (''Técnico/Administrador'', ''Programador'')');
            PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
        END IF;

        IF (SELECT COUNT(*) FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = CONCAT('e', eid, '_permisos_rol')) > 0 THEN
            SET @s = CONCAT('DELETE FROM `e', eid, '_permisos_rol` WHERE rol IN (''Técnico/Administrador'', ''Programador'')');
            PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
        END IF;
    END LOOP;
    CLOSE c;
END$$
DELIMITER ;

CALL servidesk_006_roles();
DROP PROCEDURE servidesk_006_roles;

UPDATE usuarios SET rol = 'Administrador' WHERE rol IN ('Técnico/Administrador', 'Programador');
