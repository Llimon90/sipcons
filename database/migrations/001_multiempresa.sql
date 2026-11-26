-- =====================================================================
--  Migración 001: Servidesk multiempresa
--
--  Convierte una instalación de una sola empresa en la plataforma
--  multiempresa SIN PERDER DATOS: las tablas actuales pasan a ser de la
--  empresa 1 (prefijo e1_) y sus usuarios quedan asociados a ella.
--
--  La empresa 1 se crea en estado 'demo' (la demo pública). Si migras la
--  instalación de un cliente real, cambia antes el INSERT del paso 3:
--  estado 'activa', su nombre y su slug.
--
--  EJECUTAR UNA SOLA VEZ, justo después de desplegar el código multiempresa.
--  Respalda antes la base (phpMyAdmin > Exportar).
-- =====================================================================

SET NAMES utf8mb4;

-- 1. Tablas centrales nuevas
CREATE TABLE IF NOT EXISTS `empresas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `slug` varchar(60) NOT NULL COMMENT 'Identificador corto y único (acme)',
  `nombre` varchar(150) NOT NULL,
  `estado` varchar(20) NOT NULL DEFAULT 'prueba' COMMENT 'demo | prueba | activa | suspendida | cancelada',
  `prueba_hasta` date DEFAULT NULL,
  `pagado_hasta` date DEFAULT NULL COMMENT 'Último día cubierto por los pagos (estado activa)',
  `marca` longtext COMMENT 'JSON: nombre, eslogan, logo, colores, categorias, prefijo_folio, zona_horaria',
  `contacto_nombre` varchar(150) DEFAULT NULL,
  `contacto_correo` varchar(150) DEFAULT NULL,
  `contacto_telefono` varchar(50) DEFAULT NULL,
  `notas` text,
  `registro_ip` varchar(45) DEFAULT NULL COMMENT 'IP desde la que se registró (autoservicio)',
  `creado_en` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `actualizado_en` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_slug` (`slug`),
  KEY `idx_registro_ip` (`registro_ip`, `creado_en`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `pagos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `empresa_id` int(11) NOT NULL,
  `monto` decimal(12,2) NOT NULL DEFAULT '0.00',
  `fecha_pago` date NOT NULL,
  `cubre_hasta` date NOT NULL COMMENT 'Nuevo pagado_hasta de la empresa',
  `metodo` varchar(40) DEFAULT NULL,
  `referencia` varchar(100) DEFAULT NULL,
  `notas` varchar(255) DEFAULT NULL,
  `registrado_por` varchar(150) DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_empresa` (`empresa_id`, `fecha_pago`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

-- 2. Usuarios y analíticas pasan a ser centrales: se asocian a una empresa
ALTER TABLE `usuarios`
  ADD COLUMN `empresa_id` int(11) DEFAULT NULL COMMENT 'NULL = superadministrador de la plataforma' AFTER `id`,
  MODIFY `usuario` varchar(100) NOT NULL COMMENT 'Único en toda la plataforma (normalmente el correo)',
  ADD KEY `idx_empresa` (`empresa_id`);

ALTER TABLE `analytics_eventos`
  ADD COLUMN `empresa_id` int(11) DEFAULT NULL AFTER `id`,
  ADD KEY `idx_an_empresa` (`empresa_id`, `creado_en`);

-- 3. Empresa 1
INSERT INTO `empresas` (`id`, `slug`, `nombre`, `estado`, `marca`) VALUES
(1, 'demo', 'Servidesk Demo', 'demo', '{"nombre": "Servidesk", "eslogan": "Gestión de servicios y mantenimiento", "logo": "img/logo.svg", "colores": {"primario": "#3498db", "secundario": "#1a4de4", "sidebar": "#2c3e50"}, "categorias": ["Mantenimiento correctivo", "Mantenimiento preventivo", "Instalación", "Garantía", "Diagnóstico", "Otros"], "prefijo_folio": "SD", "zona_horaria": "America/Mexico_City"}');

UPDATE `usuarios` SET `empresa_id` = 1 WHERE `empresa_id` IS NULL;
UPDATE `analytics_eventos` SET `empresa_id` = 1 WHERE `empresa_id` IS NULL;

-- 4. Las tablas de la operación pasan a ser de la empresa 1
RENAME TABLE
  `incidencias` TO `e1_incidencias`,
  `archivos_incidencias` TO `e1_archivos_incidencias`,
  `clientes` TO `e1_clientes`,
  `cliente_contactos` TO `e1_cliente_contactos`,
  `padron_equipos` TO `e1_padron_equipos`,
  `marcas` TO `e1_marcas`,
  `modelos` TO `e1_modelos`,
  `documentos` TO `e1_documentos`,
  `inventario_articulos` TO `e1_inventario_articulos`,
  `inventario_movimientos` TO `e1_inventario_movimientos`,
  `ventas` TO `e1_ventas`,
  `venta_detalles` TO `e1_venta_detalles`,
  `venta_archivos` TO `e1_venta_archivos`,
  `archivos_ventas` TO `e1_archivos_ventas`,
  `permisos_rol` TO `e1_permisos_rol`,
  `auditoria` TO `e1_auditoria`;

-- 5. Archivos: ahora viven en carpetas por empresa (uploads/e1, manuales/e1)
UPDATE `e1_documentos` SET `ruta_archivo` = REPLACE(`ruta_archivo`, 'manuales/demo/', 'manuales/e1/');
UPDATE `e1_documentos` SET `ruta_archivo` = CONCAT('manuales/e1/', SUBSTRING(`ruta_archivo`, 10))
  WHERE `ruta_archivo` LIKE 'manuales/%' AND `ruta_archivo` NOT LIKE 'manuales/e1/%';
UPDATE `e1_archivos_incidencias` SET `ruta_archivo` = REPLACE(`ruta_archivo`, '../uploads/', '../uploads/e1/')
  WHERE `ruta_archivo` NOT LIKE '%/uploads/e1/%';
UPDATE `e1_venta_archivos` SET `ruta_archivo` = REPLACE(`ruta_archivo`, '../uploads/', '../uploads/e1/')
  WHERE `ruta_archivo` NOT LIKE '%/uploads/e1/%';
