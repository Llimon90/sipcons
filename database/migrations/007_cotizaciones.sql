-- 007: Cotizaciones (Fase 1) y catálogo único de productos y servicios.
--
-- Central:
--   usuarios.puede_autorizar_descuentos: el usuario puede rebasar el descuento
--   máximo de la empresa en una cotización (el Administrador siempre puede).
--
-- En cada empresa (tablas e<ID>_):
--   inventario_articulos.controla_existencia: 0 = servicio o mano de obra
--     (se cotiza con su precio y costo, pero no lleva existencias).
--   cotizaciones, cotizacion_partidas, cotizacion_ventas (tablas nuevas).
--   permisos_rol: módulo "cotizaciones" (Técnico sin acceso al módulo; crea
--     borradores desde sus incidencias).
--
-- La configuración (prefijo, vigencia, IVA, términos, descuento máximo) vive
-- en empresas.marca -> "cotizaciones" y tiene valores por defecto en el código.
-- Se puede ejecutar más de una vez. Para deshacerlo: 007_cotizaciones_revertir.sql.

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'puede_autorizar_descuentos') = 0,
    'ALTER TABLE usuarios ADD COLUMN puede_autorizar_descuentos TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''1 = puede rebasar el descuento máximo en cotizaciones''',
    'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

DROP PROCEDURE IF EXISTS servidesk_007_empresa;
DROP PROCEDURE IF EXISTS servidesk_007;

DELIMITER $$
CREATE PROCEDURE servidesk_007_empresa(IN p VARCHAR(20))
BEGIN
    -- Catálogo único: servicios sin existencias
    IF (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = CONCAT(p, 'inventario_articulos') AND COLUMN_NAME = 'controla_existencia') = 0 THEN
        SET @s = CONCAT('ALTER TABLE `', p, 'inventario_articulos` ADD COLUMN controla_existencia TINYINT(1) NOT NULL DEFAULT 1 ',
            'COMMENT ''0 = servicio o mano de obra: sin existencias'' AFTER categoria');
        PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
    END IF;

    SET @s = CONCAT('CREATE TABLE IF NOT EXISTS `', p, 'cotizaciones` (',
        'id INT(11) NOT NULL AUTO_INCREMENT,',
        'folio VARCHAR(30) NOT NULL,',
        'folio_base VARCHAR(20) NOT NULL COMMENT ''Folio de la serie (COT-0001); las versiones agregan -v2, -v3'',',
        'version INT(11) NOT NULL DEFAULT 1,',
        'cotizacion_origen_id INT(11) DEFAULT NULL COMMENT ''Primera versión de la serie'',',
        'reemplazada_por INT(11) DEFAULT NULL COMMENT ''Versión que la sustituye (queda solo de consulta)'',',
        'cliente_id INT(11) DEFAULT NULL,',
        'cliente_nombre VARCHAR(255) NOT NULL,',
        'sucursal VARCHAR(255) DEFAULT NULL,',
        'contacto_id INT(10) UNSIGNED DEFAULT NULL,',
        'contacto_nombre VARCHAR(150) DEFAULT NULL,',
        'incidencia_id INT(11) DEFAULT NULL,',
        'venta_id INT(11) DEFAULT NULL COMMENT ''Primera venta registrada desde la cotización'',',
        'estatus VARCHAR(20) NOT NULL DEFAULT ''borrador'' COMMENT ''borrador | enviada | aprobada | rechazada | vencida | convertida'',',
        'aprobacion_parcial TINYINT(1) NOT NULL DEFAULT 0,',
        'fecha DATE NOT NULL,',
        'vigencia_hasta DATE NOT NULL,',
        'moneda CHAR(3) NOT NULL DEFAULT ''MXN'',',
        'tipo_cambio DECIMAL(12,4) NOT NULL DEFAULT 1.0000,',
        'precios_incluyen_iva TINYINT(1) NOT NULL DEFAULT 0,',
        'condiciones TEXT,',
        'notas_cliente TEXT,',
        'notas_internas TEXT,',
        'subtotal DECIMAL(14,2) NOT NULL DEFAULT 0.00,',
        'descuento_total DECIMAL(14,2) NOT NULL DEFAULT 0.00,',
        'iva_total DECIMAL(14,2) NOT NULL DEFAULT 0.00,',
        'total DECIMAL(14,2) NOT NULL DEFAULT 0.00,',
        'total_aprobado DECIMAL(14,2) DEFAULT NULL COMMENT ''Total de las partidas que el cliente aceptó'',',
        'motivo_rechazo VARCHAR(255) DEFAULT NULL,',
        'aprobada_por_nombre VARCHAR(150) DEFAULT NULL,',
        'fecha_aprobacion DATETIME DEFAULT NULL,',
        'fecha_envio DATETIME DEFAULT NULL,',
        'token_publico VARCHAR(64) DEFAULT NULL COMMENT ''Fase 2: enlace público de aprobación'',',
        'creado_por INT(11) DEFAULT NULL,',
        'creado_por_nombre VARCHAR(150) DEFAULT NULL,',
        'actualizado_por INT(11) DEFAULT NULL,',
        'creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,',
        'actualizado_en DATETIME DEFAULT NULL,',
        'PRIMARY KEY (id),',
        'UNIQUE KEY uniq_folio (folio),',
        'UNIQUE KEY uniq_token (token_publico),',
        'KEY idx_estatus (estatus, vigencia_hasta),',
        'KEY idx_cliente (cliente_id),',
        'KEY idx_incidencia (incidencia_id),',
        'KEY idx_serie (folio_base, version)',
        ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

    SET @s = CONCAT('CREATE TABLE IF NOT EXISTS `', p, 'cotizacion_partidas` (',
        'id INT(11) NOT NULL AUTO_INCREMENT,',
        'cotizacion_id INT(11) NOT NULL,',
        'orden INT(11) NOT NULL DEFAULT 0,',
        'tipo VARCHAR(20) NOT NULL COMMENT ''equipo | refaccion | servicio | mano_obra | poliza'',',
        'articulo_id INT(11) DEFAULT NULL COMMENT ''Artículo o servicio del catálogo (inventario_articulos)'',',
        'sku VARCHAR(40) DEFAULT NULL,',
        'descripcion VARCHAR(255) NOT NULL,',
        'marca VARCHAR(100) DEFAULT NULL,',
        'modelo VARCHAR(100) DEFAULT NULL,',
        'unidad VARCHAR(20) NOT NULL DEFAULT ''pza'',',
        'cantidad DECIMAL(12,2) NOT NULL DEFAULT 1.00,',
        'precio_unitario DECIMAL(14,2) NOT NULL DEFAULT 0.00 COMMENT ''Congelado al cotizar, en la moneda de la cotización'',',
        'descuento_pct DECIMAL(5,2) NOT NULL DEFAULT 0.00,',
        'tasa_iva DECIMAL(4,2) NOT NULL DEFAULT 0.16,',
        'es_exento TINYINT(1) NOT NULL DEFAULT 0,',
        'costo_unitario DECIMAL(14,2) NOT NULL DEFAULT 0.00 COMMENT ''Copia del costo al cotizar (margen); nunca se muestra al cliente'',',
        'importe DECIMAL(14,2) NOT NULL DEFAULT 0.00 COMMENT ''Sin IVA, después del descuento'',',
        'descuento_importe DECIMAL(14,2) NOT NULL DEFAULT 0.00,',
        'iva DECIMAL(14,2) NOT NULL DEFAULT 0.00,',
        'opcional TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''Fase 3'',',
        'aceptada TINYINT(1) DEFAULT NULL COMMENT ''NULL = sin decidir; 1/0 al aprobar'',',
        'cargada_incidencia TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''Refacción ya registrada en la incidencia ligada'',',
        'PRIMARY KEY (id),',
        'KEY idx_cotizacion (cotizacion_id, orden),',
        'KEY idx_articulo (articulo_id),',
        'CONSTRAINT `fk_', p, 'partida_cotizacion` FOREIGN KEY (cotizacion_id) REFERENCES `', p, 'cotizaciones` (id) ON DELETE CASCADE',
        ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

    SET @s = CONCAT('CREATE TABLE IF NOT EXISTS `', p, 'cotizacion_ventas` (',
        'id INT(11) NOT NULL AUTO_INCREMENT,',
        'cotizacion_id INT(11) NOT NULL,',
        'articulo_id INT(11) DEFAULT NULL,',
        'descripcion VARCHAR(255) NOT NULL,',
        'marca VARCHAR(100) DEFAULT NULL,',
        'modelo VARCHAR(100) DEFAULT NULL,',
        'cantidad DECIMAL(12,2) NOT NULL DEFAULT 1.00,',
        'estado VARCHAR(20) NOT NULL DEFAULT ''por_registrar'' COMMENT ''por_registrar | registrada | descartada'',',
        'venta_id INT(11) DEFAULT NULL,',
        'creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,',
        'actualizado_en DATETIME DEFAULT NULL,',
        'PRIMARY KEY (id),',
        'KEY idx_estado (estado),',
        'KEY idx_cotizacion (cotizacion_id),',
        'CONSTRAINT `fk_', p, 'cventa_cotizacion` FOREIGN KEY (cotizacion_id) REFERENCES `', p, 'cotizaciones` (id) ON DELETE CASCADE',
        ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

    SET @s = CONCAT('INSERT IGNORE INTO `', p, 'permisos_rol` (rol, modulo, permitido) VALUES ',
        '(''Administrador'', ''cotizaciones'', 1), (''Supervisor'', ''cotizaciones'', 1), ',
        '(''Administrativo'', ''cotizaciones'', 1), (''Técnico'', ''cotizaciones'', 0)');
    PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
END$$

CREATE PROCEDURE servidesk_007()
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
            AND TABLE_NAME = CONCAT('e', eid, '_inventario_articulos')) > 0 THEN
            CALL servidesk_007_empresa(CONCAT('e', eid, '_'));
        END IF;
    END LOOP;
    CLOSE c;
END$$
DELIMITER ;

CALL servidesk_007();
DROP PROCEDURE servidesk_007;
DROP PROCEDURE servidesk_007_empresa;
