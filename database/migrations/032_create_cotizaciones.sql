-- Migración 032: cotizaciones a clientes (previas a una venta)
--
-- Objetivo: poder cotizar antes de vender, con folio propio (COT-00001, mismo
-- estilo que VT-00001/SIP-0001), e historial de líneas. Una cotización puede
-- "convertirse" en venta (backend/convertir-cotizacion-venta.php): ese flujo crea
-- la venta real y guarda venta_id aquí, sin borrar la cotización (queda como
-- registro histórico con estatus 'Convertida').
--
-- 100% aditiva: solo crea tablas nuevas.
--
-- Cómo aplicar: igual que las migraciones anteriores, primero en apptest.

CREATE TABLE IF NOT EXISTS `cotizaciones` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `folio` VARCHAR(20) NOT NULL,
  `cliente_id` INT NOT NULL,
  `cliente` VARCHAR(150) NOT NULL,
  `sucursal` VARCHAR(150) NULL,
  `fecha` DATE NOT NULL,
  `fecha_vigencia` DATE NULL,
  `estatus` ENUM('Pendiente','Aprobada','Rechazada','Convertida','Vencida') NOT NULL DEFAULT 'Pendiente',
  `subtotal` DECIMAL(10,2) NOT NULL DEFAULT 0,
  `iva` DECIMAL(10,2) NOT NULL DEFAULT 0,
  `total` DECIMAL(10,2) NOT NULL DEFAULT 0,
  `notas` TEXT NULL,
  `venta_id` INT NULL,
  `creado_por` VARCHAR(150) NULL,
  `creado_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_folio` (`folio`),
  KEY `idx_cotizaciones_cliente_id` (`cliente_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `cotizacion_detalles` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `cotizacion_id` INT UNSIGNED NOT NULL,
  `producto_id` INT UNSIGNED NULL,
  `descripcion` VARCHAR(255) NOT NULL,
  `cantidad` DECIMAL(10,2) NOT NULL DEFAULT 1,
  `precio_unitario` DECIMAL(10,2) NOT NULL DEFAULT 0,
  `importe` DECIMAL(10,2) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_detalle_cotizacion_id` (`cotizacion_id`),
  CONSTRAINT `fk_detalle_cotizacion` FOREIGN KEY (`cotizacion_id`) REFERENCES `cotizaciones` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
