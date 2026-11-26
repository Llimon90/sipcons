-- ============================================================================
-- Esquema BASE reconstruido para DESARROLLO LOCAL únicamente.
--
-- Las tablas núcleo de SIPCONS (incidencias, usuarios, clientes, ventas,
-- padrón de equipos, soporte/manuales) nunca se documentaron como CREATE
-- TABLE en este repo: ya existían creadas a mano en los servidores reales
-- antes de que existiera database/migrations/. Este archivo es una
-- reconstrucción APROXIMADA, inferida leyendo los INSERT/SELECT reales en
-- backend/*.php, pensada solo para poder correr PHP + MySQL en una máquina
-- de desarrollo y probar los upgrades de esta noche contra datos reales.
--
-- NO es el esquema oficial de producción/apptest. NUNCA ejecutar esto
-- contra una base de datos que ya tenga tablas (la mayoría de los CREATE
-- fallarían o, peor, podrían chocar con datos reales) — solo contra una
-- base de datos nueva y vacía, como `sipcons_pruebas` en local.
--
-- Después de este archivo se deben aplicar, EN ORDEN, todas las migraciones
-- de database/migrations/001_*.sql en adelante: ellas asumen exactamente
-- estas columnas base (incluyendo los nombres viejos `numero`/`equipo` en
-- incidencias, que las migraciones 010 y 013 transforman).
-- ============================================================================

CREATE TABLE IF NOT EXISTS `usuarios` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nombre` VARCHAR(150) NOT NULL,
  `correo` VARCHAR(150) NULL,
  `telefono` VARCHAR(50) NULL,
  `usuario` VARCHAR(100) NOT NULL,
  `password` VARCHAR(255) NOT NULL,
  `rol` VARCHAR(50) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_usuario` (`usuario`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `clientes` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nombre` VARCHAR(150) NOT NULL,
  `rfc` VARCHAR(20) NULL,
  `direccion` VARCHAR(255) NULL,
  `telefono` VARCHAR(50) NULL,
  `contactos` VARCHAR(500) NULL,
  `email` VARCHAR(150) NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Nombres viejos a propósito: `numero` y `equipo`. Las migraciones 010 y 013
-- los transforman en numero_serie/reporte_cliente/categoria en el orden
-- correcto. No renombrar aquí.
CREATE TABLE IF NOT EXISTS `incidencias` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `numero` VARCHAR(50) NULL,
  `cliente` VARCHAR(150) NOT NULL,
  `contacto` VARCHAR(150) NOT NULL,
  `sucursal` VARCHAR(100) NULL,
  `equipo` VARCHAR(100) NULL,
  `fecha` DATE NULL,
  `tecnico` VARCHAR(150) NULL,
  `estatus` VARCHAR(50) NULL,
  `falla` TEXT NULL,
  `accion` TEXT NULL,
  `notas` TEXT NULL,
  `numero_incidente` VARCHAR(20) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_numero_incidente` (`numero_incidente`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `archivos_incidencias` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `incidencia_id` INT UNSIGNED NOT NULL,
  `ruta_archivo` VARCHAR(500) NOT NULL,
  `subido_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_incidencia` (`incidencia_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `ventas` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `folio` VARCHAR(20) NOT NULL,
  `cliente` VARCHAR(150) NOT NULL,
  `sucursal` VARCHAR(100) NULL,
  `fecha_registro` DATE NULL,
  `fecha_actualizacion` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_folio` (`folio`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `venta_detalles` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `venta_id` INT UNSIGNED NOT NULL,
  `equipo` VARCHAR(100) NULL,
  `marca` VARCHAR(100) NULL,
  `modelo` VARCHAR(100) NULL,
  `numero_serie` VARCHAR(100) NULL,
  `garantia` INT NULL,
  `calibracion` INT NULL,
  `servicio` TINYINT(1) NOT NULL DEFAULT 0,
  `frecuencia_servicio` INT NULL,
  `notas` TEXT NULL,
  `proxima_calibracion` DATE NULL,
  `proximo_servicio` DATE NULL,
  PRIMARY KEY (`id`),
  KEY `idx_venta` (`venta_id`),
  KEY `idx_numero_serie` (`numero_serie`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `venta_archivos` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `venta_id` INT UNSIGNED NOT NULL,
  `nombre_archivo` VARCHAR(255) NOT NULL,
  `ruta_archivo` VARCHAR(500) NOT NULL,
  `tipo_archivo` VARCHAR(100) NULL,
  `subido_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_venta` (`venta_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `padron_equipos` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `cliente` VARCHAR(150) NOT NULL,
  `sucursal` VARCHAR(100) NULL,
  `equipo` VARCHAR(100) NULL,
  `marca` VARCHAR(100) NULL,
  `modelo` VARCHAR(100) NULL,
  `numero_serie` VARCHAR(100) NULL,
  `calibracion` INT NULL,
  `servicio` TINYINT(1) NOT NULL DEFAULT 0,
  `frecuencia_servicio` INT NULL,
  `garantia` INT NULL,
  `proxima_calibracion` DATE NULL,
  `proximo_servicio` DATE NULL,
  `origen` VARCHAR(50) NULL,
  `venta_id` INT UNSIGNED NULL,
  `fecha_registro` DATE NULL,
  PRIMARY KEY (`id`),
  KEY `idx_numero_serie` (`numero_serie`),
  KEY `idx_venta` (`venta_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `marcas` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nombre` VARCHAR(100) NOT NULL,
  `activo` TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_nombre` (`nombre`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `modelos` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `marca_id` INT UNSIGNED NOT NULL,
  `nombre` VARCHAR(100) NOT NULL,
  `tipo_equipo` VARCHAR(100) NULL,
  `activo` TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_marca` (`marca_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `documentos` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `modelo_id` INT UNSIGNED NOT NULL,
  `tipo_documento` VARCHAR(50) NULL,
  `nombre_archivo` VARCHAR(255) NULL,
  `descripcion` VARCHAR(255) NULL,
  `ruta_archivo` VARCHAR(500) NULL,
  `subido_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_modelo` (`modelo_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Nota: `cliente_contactos` NO se crea aquí; la crea la migración
-- 007_create_cliente_contactos.sql al aplicarse en orden.
