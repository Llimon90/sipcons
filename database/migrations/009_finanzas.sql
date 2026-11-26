-- 009: Finanzas (cuentas por cobrar, cobros y gastos).
--
-- En cada empresa (tablas e<ID>_):
--   cuentas_cobrar  lo que un cliente debe: nace de una cotización aprobada,
--                   de una orden de servicio, de una venta o se captura suelta.
--                   La factura se emite fuera del sistema; aquí solo se anota su folio.
--   cobros          pagos recibidos, aplicados a una cuenta por cobrar.
--   gastos          egresos de la empresa; opcionalmente ligados a una orden
--                   de servicio (costo directo para la rentabilidad).
--   clientes.dias_credito  días para calcular el vencimiento (0 = de contado).
--   permisos_rol: módulo "finanzas" (Administrador y Administrativo).
--
-- Nada se borra: cuentas, cobros y gastos se cancelan con motivo.
-- Se puede ejecutar más de una vez. Para deshacerlo: 009_finanzas_revertir.sql.

DROP PROCEDURE IF EXISTS servidesk_009_empresa;
DROP PROCEDURE IF EXISTS servidesk_009;

DELIMITER $$
CREATE PROCEDURE servidesk_009_empresa(IN p VARCHAR(20))
BEGIN
    IF (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = CONCAT(p, 'clientes') AND COLUMN_NAME = 'dias_credito') = 0 THEN
        SET @s = CONCAT('ALTER TABLE `', p, 'clientes` ADD COLUMN dias_credito INT(11) NOT NULL DEFAULT 0 ',
            'COMMENT ''Días de crédito para el vencimiento de sus cuentas (0 = contado)''');
        PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
    END IF;

    SET @s = CONCAT('CREATE TABLE IF NOT EXISTS `', p, 'cuentas_cobrar` (',
        'id INT(11) NOT NULL AUTO_INCREMENT,',
        'folio VARCHAR(20) NOT NULL,',
        'cliente_id INT(11) DEFAULT NULL,',
        'cliente_nombre VARCHAR(255) NOT NULL,',
        'concepto VARCHAR(255) NOT NULL,',
        'origen_tipo VARCHAR(20) DEFAULT NULL COMMENT ''cotizacion | incidencia | venta'',',
        'origen_id INT(11) DEFAULT NULL,',
        'origen_folio VARCHAR(100) DEFAULT NULL,',
        'fecha DATE NOT NULL,',
        'vencimiento DATE NOT NULL,',
        'subtotal DECIMAL(14,2) NOT NULL DEFAULT 0.00,',
        'iva DECIMAL(14,2) NOT NULL DEFAULT 0.00,',
        'retenciones DECIMAL(14,2) NOT NULL DEFAULT 0.00,',
        'total DECIMAL(14,2) NOT NULL DEFAULT 0.00,',
        'cobrado DECIMAL(14,2) NOT NULL DEFAULT 0.00,',
        'factura VARCHAR(100) DEFAULT NULL COMMENT ''Folio o UUID de la factura emitida fuera del sistema'',',
        'estatus VARCHAR(15) NOT NULL DEFAULT ''pendiente'' COMMENT ''pendiente | parcial | pagada | cancelada'',',
        'motivo_cancelacion VARCHAR(255) DEFAULT NULL,',
        'notas TEXT,',
        'creado_por INT(11) DEFAULT NULL,',
        'creado_por_nombre VARCHAR(150) DEFAULT NULL,',
        'creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,',
        'actualizado_en DATETIME DEFAULT NULL,',
        'PRIMARY KEY (id),',
        'UNIQUE KEY uniq_folio (folio),',
        'KEY idx_cliente (cliente_id),',
        'KEY idx_estatus (estatus, vencimiento),',
        'KEY idx_origen (origen_tipo, origen_id)',
        ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

    SET @s = CONCAT('CREATE TABLE IF NOT EXISTS `', p, 'cobros` (',
        'id INT(11) NOT NULL AUTO_INCREMENT,',
        'cuenta_id INT(11) NOT NULL,',
        'fecha DATE NOT NULL,',
        'monto DECIMAL(14,2) NOT NULL,',
        'forma VARCHAR(20) NOT NULL DEFAULT ''transferencia'' COMMENT ''transferencia | efectivo | tarjeta | cheque | otro'',',
        'referencia VARCHAR(100) DEFAULT NULL,',
        'notas VARCHAR(255) DEFAULT NULL,',
        'comprobante VARCHAR(255) DEFAULT NULL,',
        'cancelado TINYINT(1) NOT NULL DEFAULT 0,',
        'motivo_cancelacion VARCHAR(255) DEFAULT NULL,',
        'creado_por INT(11) DEFAULT NULL,',
        'creado_por_nombre VARCHAR(150) DEFAULT NULL,',
        'creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,',
        'PRIMARY KEY (id),',
        'KEY idx_cuenta (cuenta_id),',
        'KEY idx_fecha (fecha),',
        'CONSTRAINT `fk_', p, 'cobro_cuenta` FOREIGN KEY (cuenta_id) REFERENCES `', p, 'cuentas_cobrar` (id)',
        ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

    SET @s = CONCAT('CREATE TABLE IF NOT EXISTS `', p, 'gastos` (',
        'id INT(11) NOT NULL AUTO_INCREMENT,',
        'fecha DATE NOT NULL,',
        'categoria VARCHAR(60) NOT NULL,',
        'concepto VARCHAR(255) NOT NULL,',
        'proveedor VARCHAR(150) DEFAULT NULL,',
        'monto DECIMAL(14,2) NOT NULL COMMENT ''Total pagado, con IVA'',',
        'iva DECIMAL(14,2) NOT NULL DEFAULT 0.00,',
        'forma VARCHAR(20) NOT NULL DEFAULT ''transferencia'',',
        'referencia VARCHAR(100) DEFAULT NULL,',
        'incidencia_id INT(11) DEFAULT NULL COMMENT ''Orden de servicio a la que se carga (costo directo)'',',
        'incidencia_folio VARCHAR(100) DEFAULT NULL,',
        'comprobante VARCHAR(255) DEFAULT NULL,',
        'cancelado TINYINT(1) NOT NULL DEFAULT 0,',
        'motivo_cancelacion VARCHAR(255) DEFAULT NULL,',
        'creado_por INT(11) DEFAULT NULL,',
        'creado_por_nombre VARCHAR(150) DEFAULT NULL,',
        'creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,',
        'actualizado_en DATETIME DEFAULT NULL,',
        'PRIMARY KEY (id),',
        'KEY idx_fecha (fecha),',
        'KEY idx_categoria (categoria),',
        'KEY idx_incidencia (incidencia_id)',
        ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

    SET @s = CONCAT('INSERT IGNORE INTO `', p, 'permisos_rol` (rol, modulo, permitido) VALUES ',
        '(''Administrador'', ''finanzas'', 1), (''Supervisor'', ''finanzas'', 0), ',
        '(''Administrativo'', ''finanzas'', 1), (''Técnico'', ''finanzas'', 0)');
    PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
END$$

CREATE PROCEDURE servidesk_009()
BEGIN
    DECLARE fin INT DEFAULT 0;
    DECLARE eid INT;
    DECLARE c CURSOR FOR SELECT id FROM empresas;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET fin = 1;
    OPEN c;
    ciclo: LOOP
        FETCH c INTO eid;
        IF fin THEN LEAVE ciclo; END IF;
        -- Solo empresas cuyas tablas ya existen
        IF (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = CONCAT('e', eid, '_clientes')) > 0 THEN
            CALL servidesk_009_empresa(CONCAT('e', eid, '_'));
        END IF;
    END LOOP;
    CLOSE c;
END$$
DELIMITER ;

CALL servidesk_009();
DROP PROCEDURE servidesk_009;
DROP PROCEDURE servidesk_009_empresa;
