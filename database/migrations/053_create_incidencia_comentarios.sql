-- Migración 053: comentarios/timeline de una incidencia, independientes del
-- campo de texto libre "notas". Un comentario puede marcarse visible para el
-- cliente (se expone en el portal); por default solo es interno.
--
-- 100% aditiva.
-- Cómo aplicar: igual que las migraciones anteriores, primero en apptest.

CREATE TABLE IF NOT EXISTS `incidencia_comentarios` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `incidencia_id` INT NOT NULL,
  `autor_id` INT NULL,
  `autor_nombre` VARCHAR(150) NOT NULL,
  `comentario` TEXT NOT NULL,
  `visible_cliente` TINYINT(1) NOT NULL DEFAULT 0,
  `creado_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_incidencia` (`incidencia_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
