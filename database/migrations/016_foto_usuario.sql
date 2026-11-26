-- 016: foto de perfil del usuario (versión 2.16.0; D-46).
-- usuarios.foto: ruta relativa de la foto (uploads/e<ID>/usuarios/...), recodificada en el
-- servidor sin metadatos. Cada usuario sube solo la suya.
-- Se puede ejecutar más de una vez.

DROP PROCEDURE IF EXISTS servidesk_016;

DELIMITER $$
CREATE PROCEDURE servidesk_016()
BEGIN
    IF (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'foto') = 0 THEN
        ALTER TABLE usuarios ADD COLUMN foto VARCHAR(255) DEFAULT NULL
            COMMENT 'Foto de perfil (uploads/e<ID>/usuarios/...); D-46';
    END IF;
END$$
DELIMITER ;

CALL servidesk_016();
DROP PROCEDURE servidesk_016;
