-- 023: corrige acentos dañados por la migración 010 (U-1, auditoría integral 2026-11-24). Versión 2.22.3.
--
-- La 010 no fijaba la codificación de la conexión: si se importó desde una consola que no era UTF-8,
-- el motivo "Otro: sin motivo registrado (antes de la versión 2.13)" quedó como "versi├│n".
-- Esta migración solo cambia los textos dañados; si en esta base están bien, no toca nada.
-- Toda migración nueva empieza con SET NAMES utf8mb4. Se puede ejecutar más de una vez.
SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS servidesk_023;
DELIMITER $$
CREATE PROCEDURE servidesk_023()
BEGIN
    DECLARE fin INT DEFAULT 0;
    DECLARE t VARCHAR(64);
    DECLARE c CURSOR FOR SELECT TABLE_NAME FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'e%\_incidencias' AND COLUMN_NAME = 'motivo_estatus';
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET fin = 1;
    OPEN c;
    ciclo: LOOP
        FETCH c INTO t;
        IF fin THEN LEAVE ciclo; END IF;
        SET @s = CONCAT('UPDATE `', t, '` SET motivo_estatus = REPLACE(motivo_estatus, ''versi', CHAR(0xE2949C USING utf8mb4), CHAR(0xE29482 USING utf8mb4), 'n'', ''versión'') ',
                        'WHERE motivo_estatus LIKE CONCAT(''%versi'', CHAR(0xE2949C USING utf8mb4), ''%'')');
        PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
    END LOOP;
    CLOSE c;
END$$
DELIMITER ;
CALL servidesk_023();
DROP PROCEDURE servidesk_023;
