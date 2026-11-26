-- 015: conceptos aplicados en la orden de servicio (versión 2.15.0; D-23 punto 2).
--
-- En cada empresa (tablas e<ID>_):
--   incidencia_servicios   servicios y mano de obra aplicados en una orden (no tienen
--                          existencias: no pasan por inventario_movimientos).
--   padron_equipos.reemplazo_incidencia_id / reemplazo_articulo_id
--                          equipo de reemplazo instalado desde una orden: liga el equipo
--                          del padrón con la orden y el artículo de inventario.
-- Se puede ejecutar más de una vez.

DROP PROCEDURE IF EXISTS servidesk_015_empresa;
DROP PROCEDURE IF EXISTS servidesk_015;

DELIMITER $$
CREATE PROCEDURE servidesk_015_empresa(IN p VARCHAR(20))
BEGIN
    SET @s = CONCAT('CREATE TABLE IF NOT EXISTS `', p, 'incidencia_servicios` (',
        'id INT(11) NOT NULL AUTO_INCREMENT,',
        'incidencia_id INT(11) NOT NULL,',
        'articulo_id INT(11) NOT NULL,',
        'descripcion VARCHAR(255) NOT NULL,',
        'unidad VARCHAR(30) DEFAULT NULL,',
        'cantidad DECIMAL(12,2) NOT NULL DEFAULT 1.00,',
        'precio_unitario DECIMAL(14,2) NOT NULL DEFAULT 0.00,',
        'creado_por INT(11) DEFAULT NULL,',
        'creado_por_nombre VARCHAR(150) DEFAULT NULL,',
        'creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,',
        'PRIMARY KEY (id),',
        'KEY idx_incidencia (incidencia_id)',
        ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

    IF (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = CONCAT(p, 'padron_equipos') AND COLUMN_NAME = 'reemplazo_incidencia_id') = 0 THEN
        SET @s = CONCAT('ALTER TABLE `', p, 'padron_equipos` ',
            'ADD COLUMN reemplazo_incidencia_id INT(11) DEFAULT NULL COMMENT ''Orden de servicio donde se instaló como reemplazo'', ',
            'ADD COLUMN reemplazo_articulo_id INT(11) DEFAULT NULL COMMENT ''Artículo de inventario instalado'', ',
            'ADD KEY idx_reemplazo (reemplazo_incidencia_id)');
        PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
    END IF;
END$$

CREATE PROCEDURE servidesk_015()
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
            CALL servidesk_015_empresa(CONCAT('e', eid, '_'));
        END IF;
    END LOOP;
    CLOSE c;
END$$
DELIMITER ;

CALL servidesk_015();
DROP PROCEDURE servidesk_015;
DROP PROCEDURE servidesk_015_empresa;
