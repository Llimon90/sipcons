-- 021: contraseña temporal (L-13, auditoría integral 2026-11-24). Versión 2.22.0.
--
-- usuarios.debe_cambiar_password   1 = la contraseña la entregó Solid Admin (alta o restablecer acceso)
--                                  y el usuario debe cambiarla antes de usar el sistema.
-- Se puede ejecutar más de una vez. Sin esta migración el sistema funciona como antes.
SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS servidesk_021;
DELIMITER $$
CREATE PROCEDURE servidesk_021()
BEGIN
    IF (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'debe_cambiar_password') = 0 THEN
        ALTER TABLE usuarios ADD COLUMN debe_cambiar_password TINYINT(1) NOT NULL DEFAULT 0
            COMMENT '1 = contraseña temporal del panel; se cambia al entrar (L-13)';
    END IF;
END$$
DELIMITER ;
CALL servidesk_021();
DROP PROCEDURE servidesk_021;
