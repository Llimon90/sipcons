-- 011: Folios y doble envío (versión 2.13.2; docs/REGLAS_NEGOCIO.md D-7, CV-12, D-36).
--
-- En cada empresa (tablas e<ID>_):
--   folios          contador por tipo de documento ('incidencias', 'ventas'). Nunca
--                   retrocede: el folio de un registro eliminado no se vuelve a usar.
--                   Se inicia con el número más alto que ya existe.
--   incidencias.clave_envio  clave única de cada formulario de alta: si el mismo
--                   envío llega dos veces (doble clic, red lenta), se crea una sola orden.
--   UNIQUE incidencias.numero_incidente y ventas.folio (ventas.folio pasa a VARCHAR(20)).
--   Antes, los folios repetidos que ya existan se renombran: <folio>-<id> (el más antiguo
--   conserva el suyo).
--
-- En la tabla central usuarios:
--   UNIQUE (empresa_id, nombre): el nombre no se repite dentro de una empresa (AS-7);
--   otra empresa sí puede usarlo (D-36). Los repetidos que ya existan se renombran:
--   "<nombre> (<id>)" (el más antiguo conserva el suyo).
--
-- Se puede ejecutar más de una vez. Para deshacerlo: 011_folios_unicos_revertir.sql.

DROP PROCEDURE IF EXISTS servidesk_011_empresa;
DROP PROCEDURE IF EXISTS servidesk_011;

DELIMITER $$
CREATE PROCEDURE servidesk_011_empresa(IN p VARCHAR(20))
BEGIN
    -- Contador de folios
    SET @s = CONCAT('CREATE TABLE IF NOT EXISTS `', p, 'folios` (',
        'clave VARCHAR(30) NOT NULL,',
        'ultimo INT(11) NOT NULL DEFAULT 0,',
        'PRIMARY KEY (clave)',
        ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

    SET @s = CONCAT('INSERT IGNORE INTO `', p, 'folios` (clave, ultimo) ',
        'SELECT ''incidencias'', COALESCE(MAX(CASE WHEN numero_incidente REGEXP ''-[0-9]+$'' THEN CAST(SUBSTRING_INDEX(numero_incidente, ''-'', -1) AS UNSIGNED) END), 0) FROM `', p, 'incidencias`');
    PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

    IF (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = CONCAT(p, 'ventas')) > 0 THEN
        SET @s = CONCAT('INSERT IGNORE INTO `', p, 'folios` (clave, ultimo) ',
            'SELECT ''ventas'', COALESCE(MAX(CASE WHEN folio REGEXP ''-[0-9]+$'' THEN CAST(SUBSTRING_INDEX(folio, ''-'', -1) AS UNSIGNED) END), 0) FROM `', p, 'ventas`');
        PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

        SET @s = CONCAT('ALTER TABLE `', p, 'ventas` MODIFY folio VARCHAR(20) NOT NULL');
        PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

        SET @s = CONCAT('UPDATE `', p, 'ventas` t JOIN (SELECT folio, MIN(id) AS primero FROM `', p, 'ventas` ',
            'GROUP BY folio HAVING COUNT(*) > 1) d ON t.folio = d.folio AND t.id <> d.primero ',
            'SET t.folio = LEFT(CONCAT(t.folio, ''-'', t.id), 20)');
        PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

        IF (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = CONCAT(p, 'ventas') AND INDEX_NAME = 'uniq_folio') = 0 THEN
            SET @s = CONCAT('ALTER TABLE `', p, 'ventas` ADD UNIQUE KEY uniq_folio (folio)');
            PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
        END IF;
    END IF;

    -- Clave de envío del alta (D-7)
    IF (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = CONCAT(p, 'incidencias') AND COLUMN_NAME = 'clave_envio') = 0 THEN
        SET @s = CONCAT('ALTER TABLE `', p, 'incidencias` ADD COLUMN clave_envio VARCHAR(64) DEFAULT NULL ',
            'COMMENT ''Clave única del formulario de alta (evita el doble envío)'', ADD UNIQUE KEY uniq_clave_envio (clave_envio)');
        PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
    END IF;

    -- Folio único de orden de servicio
    SET @s = CONCAT('UPDATE `', p, 'incidencias` t JOIN (SELECT numero_incidente, MIN(id) AS primero FROM `', p, 'incidencias` ',
        'WHERE numero_incidente IS NOT NULL GROUP BY numero_incidente HAVING COUNT(*) > 1) d ',
        'ON t.numero_incidente = d.numero_incidente AND t.id <> d.primero ',
        'SET t.numero_incidente = CONCAT(t.numero_incidente, ''-'', t.id)');
    PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

    IF (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = CONCAT(p, 'incidencias') AND INDEX_NAME = 'uniq_numero_incidente') = 0 THEN
        SET @s = CONCAT('ALTER TABLE `', p, 'incidencias` ADD UNIQUE KEY uniq_numero_incidente (numero_incidente)');
        PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
    END IF;
END$$

CREATE PROCEDURE servidesk_011()
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
            AND TABLE_NAME = CONCAT('e', eid, '_incidencias')) > 0 THEN
            CALL servidesk_011_empresa(CONCAT('e', eid, '_'));
        END IF;
    END LOOP;
    CLOSE c;

    -- Nombre de usuario único dentro de cada empresa (AS-7, D-36)
    UPDATE usuarios t JOIN (SELECT empresa_id, nombre, MIN(id) AS primero FROM usuarios
        WHERE empresa_id IS NOT NULL GROUP BY empresa_id, nombre HAVING COUNT(*) > 1) d
        ON t.empresa_id = d.empresa_id AND t.nombre = d.nombre AND t.id <> d.primero
        SET t.nombre = CONCAT(LEFT(t.nombre, 85), ' (', t.id, ')');
    IF (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'usuarios' AND INDEX_NAME = 'uniq_empresa_nombre') = 0 THEN
        ALTER TABLE usuarios ADD UNIQUE KEY uniq_empresa_nombre (empresa_id, nombre);
    END IF;
END$$
DELIMITER ;

CALL servidesk_011();
DROP PROCEDURE servidesk_011;
DROP PROCEDURE servidesk_011_empresa;
