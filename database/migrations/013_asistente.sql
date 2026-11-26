-- 013: asistente guiado por rol (versión 2.14.0; docs/REGLAS_NEGOCIO.md D-20).
-- usuarios.asistente: NULL = todavía no lo ve (se abre solo en su siguiente acceso);
-- 'cerrado' = lo cerró antes de terminar; 'terminado' = lo recorrió completo.
-- Se guarda en la cuenta del usuario, no en el navegador. Se puede ejecutar más de una vez.

DROP PROCEDURE IF EXISTS servidesk_013;

DELIMITER $$
CREATE PROCEDURE servidesk_013()
BEGIN
    IF (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'asistente') = 0 THEN
        ALTER TABLE usuarios ADD COLUMN asistente VARCHAR(20) DEFAULT NULL
            COMMENT 'Asistente guiado: NULL = pendiente, cerrado, terminado (D-20)';
    END IF;
END$$
DELIMITER ;

CALL servidesk_013();
DROP PROCEDURE servidesk_013;
