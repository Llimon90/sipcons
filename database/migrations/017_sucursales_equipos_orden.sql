-- 017: multisucursal (D-44) y equipos de la orden (D-41). Versión 2.17.0.
--
-- En cada empresa (tablas e<ID>_):
--   cliente_sucursales     catálogo de sucursales de cada cliente (nombre único por cliente).
--   sucursal_id            en incidencias, padron_equipos, ventas y cotizaciones: la sucursal
--                          del catálogo. La columna de texto `sucursal` se conserva (historial).
--   incidencia_equipos     equipos (números de serie) atendidos en una orden, ligados al padrón.
-- Al final crea las sucursales a partir de los textos que ya existen y las liga.
-- Se puede ejecutar más de una vez.

DROP PROCEDURE IF EXISTS servidesk_017_ejecutar;
DROP PROCEDURE IF EXISTS servidesk_017_columna;
DROP PROCEDURE IF EXISTS servidesk_017_empresa;
DROP PROCEDURE IF EXISTS servidesk_017;

DELIMITER $$
-- Ejecuta un SQL donde {p} es el prefijo de la empresa
CREATE PROCEDURE servidesk_017_ejecutar(IN p VARCHAR(20), IN plantilla TEXT)
BEGIN
    SET @s = REPLACE(plantilla, '{p}', p);
    PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
END$$

CREATE PROCEDURE servidesk_017_columna(IN p VARCHAR(20), IN tabla VARCHAR(64))
BEGIN
    IF (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = CONCAT(p, tabla) AND COLUMN_NAME = 'sucursal_id') = 0 THEN
        CALL servidesk_017_ejecutar(p, CONCAT('ALTER TABLE `{p}', tabla, '` ADD COLUMN sucursal_id INT(11) DEFAULT NULL, ADD KEY idx_sucursal_id (sucursal_id)'));
    END IF;
END$$

CREATE PROCEDURE servidesk_017_empresa(IN p VARCHAR(20))
BEGIN
    CALL servidesk_017_ejecutar(p, 'CREATE TABLE IF NOT EXISTS `{p}cliente_sucursales` (
        id INT(11) NOT NULL AUTO_INCREMENT,
        cliente_id INT(11) NOT NULL,
        nombre VARCHAR(150) NOT NULL,
        direccion VARCHAR(255) DEFAULT NULL,
        contacto VARCHAR(150) DEFAULT NULL,
        telefono VARCHAR(30) DEFAULT NULL,
        activo TINYINT(1) NOT NULL DEFAULT 1,
        creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_cliente_nombre (cliente_id, nombre),
        KEY idx_cliente (cliente_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

    CALL servidesk_017_ejecutar(p, 'CREATE TABLE IF NOT EXISTS `{p}incidencia_equipos` (
        id INT(11) NOT NULL AUTO_INCREMENT,
        incidencia_id INT(11) NOT NULL,
        padron_id INT(11) DEFAULT NULL,
        numero_serie VARCHAR(100) NOT NULL,
        creado_por INT(11) DEFAULT NULL,
        creado_por_nombre VARCHAR(150) DEFAULT NULL,
        creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_orden_serie (incidencia_id, numero_serie),
        KEY idx_padron (padron_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

    CALL servidesk_017_columna(p, 'incidencias');
    CALL servidesk_017_columna(p, 'padron_equipos');
    CALL servidesk_017_columna(p, 'ventas');
    CALL servidesk_017_columna(p, 'cotizaciones');

    -- Sucursales a partir de los textos existentes (la intercalación no distingue mayúsculas)
    CALL servidesk_017_ejecutar(p, 'INSERT IGNORE INTO `{p}cliente_sucursales` (cliente_id, nombre)
        SELECT c.id, TRIM(x.sucursal) FROM (
            SELECT cliente, sucursal FROM `{p}incidencias`
            UNION SELECT cliente, sucursal FROM `{p}padron_equipos`
            UNION SELECT cliente, sucursal FROM `{p}ventas`) x
        JOIN `{p}clientes` c ON UPPER(TRIM(c.nombre)) = UPPER(TRIM(x.cliente))
        WHERE TRIM(COALESCE(x.sucursal, '''')) <> ''''');
    CALL servidesk_017_ejecutar(p, 'INSERT IGNORE INTO `{p}cliente_sucursales` (cliente_id, nombre)
        SELECT cliente_id, TRIM(sucursal) FROM `{p}cotizaciones` WHERE cliente_id IS NOT NULL AND TRIM(COALESCE(sucursal, '''')) <> ''''');

    CALL servidesk_017_ejecutar(p, 'UPDATE `{p}incidencias` t JOIN `{p}clientes` c ON UPPER(TRIM(c.nombre)) = UPPER(TRIM(t.cliente))
        JOIN `{p}cliente_sucursales` s ON s.cliente_id = c.id AND s.nombre = TRIM(t.sucursal) SET t.sucursal_id = s.id WHERE t.sucursal_id IS NULL');
    CALL servidesk_017_ejecutar(p, 'UPDATE `{p}padron_equipos` t JOIN `{p}clientes` c ON UPPER(TRIM(c.nombre)) = UPPER(TRIM(t.cliente))
        JOIN `{p}cliente_sucursales` s ON s.cliente_id = c.id AND s.nombre = TRIM(t.sucursal) SET t.sucursal_id = s.id WHERE t.sucursal_id IS NULL');
    CALL servidesk_017_ejecutar(p, 'UPDATE `{p}ventas` t JOIN `{p}clientes` c ON UPPER(TRIM(c.nombre)) = UPPER(TRIM(t.cliente))
        JOIN `{p}cliente_sucursales` s ON s.cliente_id = c.id AND s.nombre = TRIM(t.sucursal) SET t.sucursal_id = s.id WHERE t.sucursal_id IS NULL');
    CALL servidesk_017_ejecutar(p, 'UPDATE `{p}cotizaciones` t
        JOIN `{p}cliente_sucursales` s ON s.cliente_id = t.cliente_id AND s.nombre = TRIM(t.sucursal) SET t.sucursal_id = s.id WHERE t.sucursal_id IS NULL');

    -- La serie que ya tenía cada orden queda como su primer equipo
    CALL servidesk_017_ejecutar(p, 'INSERT IGNORE INTO `{p}incidencia_equipos` (incidencia_id, padron_id, numero_serie)
        SELECT i.id, (SELECT MIN(pe.id) FROM `{p}padron_equipos` pe WHERE pe.numero_serie = TRIM(i.numero_serie)), TRIM(i.numero_serie)
        FROM `{p}incidencias` i WHERE TRIM(COALESCE(i.numero_serie, '''')) <> ''''');
END$$

CREATE PROCEDURE servidesk_017()
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
            AND TABLE_NAME = CONCAT('e', eid, '_padron_equipos')) > 0 THEN
            CALL servidesk_017_empresa(CONCAT('e', eid, '_'));
        END IF;
    END LOOP;
    CLOSE c;
END$$
DELIMITER ;

CALL servidesk_017();
DROP PROCEDURE servidesk_017;
DROP PROCEDURE servidesk_017_empresa;
DROP PROCEDURE servidesk_017_columna;
DROP PROCEDURE servidesk_017_ejecutar;
