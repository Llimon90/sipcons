-- Migración 051: solicitudes de servicio del catálogo (folio SOL-00001).
-- Puede crearla el personal o el propio cliente desde el portal.
--
-- 100% aditiva.
-- Cómo aplicar: igual que las migraciones anteriores, primero en apptest.

CREATE TABLE IF NOT EXISTS `solicitudes_servicio` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `folio` VARCHAR(20) NOT NULL,
  `servicio_id` INT UNSIGNED NOT NULL,
  `cliente_id` INT NOT NULL,
  `cliente` VARCHAR(150) NOT NULL,
  `sucursal` VARCHAR(150) NULL,
  `descripcion` TEXT NULL,
  `estatus` ENUM('Pendiente','Aprobada','En proceso','Completada','Rechazada') NOT NULL DEFAULT 'Pendiente',
  `tecnico_asignado` VARCHAR(150) NULL,
  `creado_por_portal` TINYINT(1) NOT NULL DEFAULT 0,
  `fecha` DATE NOT NULL,
  `creado_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_folio` (`folio`),
  KEY `idx_cliente` (`cliente_id`),
  KEY `idx_servicio` (`servicio_id`),
  CONSTRAINT `fk_solicitud_servicio` FOREIGN KEY (`servicio_id`) REFERENCES `catalogo_servicios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
