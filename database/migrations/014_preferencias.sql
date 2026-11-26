-- 014: preferencias del usuario (versión 2.14.1; backlog O-15).
-- usuarios.preferencias: JSON con ajustes personales que se guardan en la cuenta, no en el
-- navegador (por ejemplo, "no volver a explicar qué pasa al enviar una cotización").
-- Se puede ejecutar más de una vez.

DROP PROCEDURE IF EXISTS servidesk_014;

DELIMITER $$
CREATE PROCEDURE servidesk_014()
BEGIN
    IF (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'preferencias') = 0 THEN
        ALTER TABLE usuarios ADD COLUMN preferencias TEXT DEFAULT NULL
            COMMENT 'Preferencias personales en JSON (O-15)';
    END IF;
END$$
DELIMITER ;

CALL servidesk_014();
DROP PROCEDURE servidesk_014;
