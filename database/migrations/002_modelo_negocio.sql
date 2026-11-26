-- =====================================================================
--  Migración 002: modelo de negocio del panel de plataforma
--
--  Planes y precios, facturación por empresa (plan, ciclo, precio pactado,
--  datos fiscales), pagos anulables con folio de factura, notas internas
--  de seguimiento y configuración de la plataforma editable desde el panel.
--
--  Requiere la migración 001. Se puede importar de nuevo sin riesgo si una
--  importación anterior se interrumpió (phpMyAdmin > Importar).
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `planes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `clave` varchar(30) NOT NULL,
  `nombre` varchar(80) NOT NULL,
  `descripcion` varchar(255) DEFAULT NULL,
  `precio_mensual` decimal(12,2) NOT NULL DEFAULT '0.00',
  `precio_anual` decimal(12,2) NOT NULL DEFAULT '0.00',
  `max_usuarios` int(11) DEFAULT NULL COMMENT 'NULL = sin límite',
  `activo` tinyint(1) NOT NULL DEFAULT '1',
  `orden` int(11) NOT NULL DEFAULT '0',
  `creado_en` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_clave` (`clave`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `empresa_notas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `empresa_id` int(11) NOT NULL,
  `nota` text NOT NULL,
  `autor` varchar(150) DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_empresa` (`empresa_id`, `creado_en`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `plataforma_config` (
  `clave` varchar(60) NOT NULL,
  `valor` text,
  `actualizado_en` datetime DEFAULT NULL,
  PRIMARY KEY (`clave`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Columnas e índices: cada uno se agrega solo si falta, así la migración
-- se puede volver a importar sin error (p. ej. tras una importación que se
-- interrumpió). También repara instalaciones que aplicaron la primera versión
-- de la migración 001, que aún no traía empresas.registro_ip ni plataforma_eventos.

CREATE TABLE IF NOT EXISTS `plataforma_eventos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `empresa_id` int(11) DEFAULT NULL,
  `accion` varchar(40) NOT NULL,
  `detalle` text COMMENT 'JSON con los datos de la acción',
  `usuario_nombre` varchar(150) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_empresa` (`empresa_id`, `creado_en`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'empresas' AND COLUMN_NAME = 'registro_ip') = 0,
  'ALTER TABLE `empresas` ADD COLUMN `registro_ip` varchar(45) DEFAULT NULL COMMENT ''IP desde la que se registró (autoservicio)'' AFTER `notas`', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'empresas' AND INDEX_NAME = 'idx_registro_ip') = 0,
  'ALTER TABLE `empresas` ADD KEY `idx_registro_ip` (`registro_ip`, `creado_en`)', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'empresas' AND COLUMN_NAME = 'plan_id') = 0,
  'ALTER TABLE `empresas` ADD COLUMN `plan_id` int(11) DEFAULT NULL AFTER `registro_ip`', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'empresas' AND COLUMN_NAME = 'ciclo') = 0,
  'ALTER TABLE `empresas` ADD COLUMN `ciclo` varchar(10) NOT NULL DEFAULT ''mensual'' COMMENT ''mensual | anual'' AFTER `plan_id`', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'empresas' AND COLUMN_NAME = 'precio') = 0,
  'ALTER TABLE `empresas` ADD COLUMN `precio` decimal(12,2) DEFAULT NULL COMMENT ''Precio pactado por ciclo; NULL = precio del plan'' AFTER `ciclo`', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'empresas' AND COLUMN_NAME = 'rfc') = 0,
  'ALTER TABLE `empresas` ADD COLUMN `rfc` varchar(20) DEFAULT NULL AFTER `precio`', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'empresas' AND COLUMN_NAME = 'razon_social') = 0,
  'ALTER TABLE `empresas` ADD COLUMN `razon_social` varchar(200) DEFAULT NULL AFTER `rfc`', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'empresas' AND INDEX_NAME = 'idx_plan') = 0,
  'ALTER TABLE `empresas` ADD KEY `idx_plan` (`plan_id`)', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pagos' AND COLUMN_NAME = 'factura') = 0,
  'ALTER TABLE `pagos` ADD COLUMN `factura` varchar(60) DEFAULT NULL COMMENT ''Folio o UUID del CFDI emitido'' AFTER `notas`', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pagos' AND COLUMN_NAME = 'anulado') = 0,
  'ALTER TABLE `pagos` ADD COLUMN `anulado` tinyint(1) NOT NULL DEFAULT ''0'' AFTER `registrado_por`', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pagos' AND COLUMN_NAME = 'anulado_motivo') = 0,
  'ALTER TABLE `pagos` ADD COLUMN `anulado_motivo` varchar(255) DEFAULT NULL AFTER `anulado`', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pagos' AND COLUMN_NAME = 'anulado_en') = 0,
  'ALTER TABLE `pagos` ADD COLUMN `anulado_en` datetime DEFAULT NULL AFTER `anulado_motivo`', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pagos' AND INDEX_NAME = 'idx_fecha') = 0,
  'ALTER TABLE `pagos` ADD KEY `idx_fecha` (`fecha_pago`)', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Planes iniciales (precios de referencia en MXN, editables en el panel)
INSERT IGNORE INTO `planes` (`id`, `clave`, `nombre`, `descripcion`, `precio_mensual`, `precio_anual`, `max_usuarios`, `orden`) VALUES
(1, 'basico', 'Básico', 'Para talleres y equipos pequeños', 690.00, 6900.00, 5, 1),
(2, 'profesional', 'Profesional', 'Para empresas de servicio en crecimiento', 1490.00, 14900.00, 15, 2),
(3, 'empresa', 'Empresa', 'Sin límite de usuarios', 2990.00, 29900.00, NULL, 3);

-- La demo muestra el plan más completo; las demás empresas quedan en Profesional
UPDATE `empresas` SET `plan_id` = 3 WHERE `estado` = 'demo' AND `plan_id` IS NULL;
UPDATE `empresas` SET `plan_id` = 2 WHERE `plan_id` IS NULL;
