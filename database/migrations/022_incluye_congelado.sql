-- 022: lo que incluye cada servicio se congela al enviar la cotización (L-9, auditoría integral 2026-11-24). Versión 2.22.2.
--
-- En cada empresa (tablas e<ID>_):
--   cotizacion_partidas.incluye_congelado   texto "Incluye" del servicio el día que se envió la cotización.
--     El PDF y el detalle de una cotización enviada o aprobada ya no cambian si se edita el catálogo.
--     NULL = borrador (o cotización anterior a la migración): se muestra el catálogo vivo.
-- Se puede ejecutar más de una vez. Sin esta migración el sistema funciona como antes.
SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS servidesk_022_columna;
DROP PROCEDURE IF EXISTS servidesk_022_empresa;
DROP PROCEDURE IF EXISTS servidesk_022;

DELIMITER $$
CREATE PROCEDURE servidesk_022_columna(IN p VARCHAR(20), IN tabla VARCHAR(64), IN columna VARCHAR(64), IN definicion TEXT)
BEGIN
    IF (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = CONCAT(p, tabla) AND COLUMN_NAME = columna) = 0 THEN
        SET @s = CONCAT('ALTER TABLE `', p, tabla, '` ADD COLUMN ', columna, ' ', definicion);
        PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
    END IF;
END$$

CREATE PROCEDURE servidesk_022_empresa(IN p VARCHAR(20))
BEGIN
    CALL servidesk_022_columna(p, 'cotizacion_partidas', 'incluye_congelado', 'TEXT DEFAULT NULL COMMENT ''Incluye del servicio al enviar (L-9)''');
    -- Las cotizaciones que ya salieron del borrador se congelan con el texto de hoy (H-08)
    SET @s = CONCAT('UPDATE `', p, 'cotizacion_partidas` cp JOIN `', p, 'cotizaciones` c ON c.id = cp.cotizacion_id',
                    ' JOIN `', p, 'inventario_articulos` a ON a.id = cp.articulo_id',
                    ' SET cp.incluye_congelado = COALESCE(a.descripcion, '''')',
                    ' WHERE cp.tipo = ''servicio'' AND cp.incluye_congelado IS NULL AND c.estatus <> ''borrador''');
    PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
END$$

CREATE PROCEDURE servidesk_022()
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
            AND TABLE_NAME = CONCAT('e', eid, '_cotizacion_partidas')) > 0 THEN
            CALL servidesk_022_empresa(CONCAT('e', eid, '_'));
        END IF;
    END LOOP;
    CLOSE c;
END$$
DELIMITER ;

CALL servidesk_022();
DROP PROCEDURE servidesk_022;
DROP PROCEDURE servidesk_022_empresa;
DROP PROCEDURE servidesk_022_columna;
