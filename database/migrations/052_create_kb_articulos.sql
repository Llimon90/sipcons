-- Migración 052: base de conocimiento de soluciones (no solo manuales por
-- marca/modelo como soporte.html: artículos de solución, internos o públicos).
--
-- 100% aditiva.
-- Cómo aplicar: igual que las migraciones anteriores, primero en apptest.

CREATE TABLE IF NOT EXISTS `kb_articulos` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `titulo` VARCHAR(200) NOT NULL,
  `contenido` TEXT NOT NULL,
  `categoria` VARCHAR(100) NULL,
  `visibilidad` ENUM('Interno','Publico') NOT NULL DEFAULT 'Interno',
  `autor_id` INT NULL,
  `autor_nombre` VARCHAR(150) NULL,
  `vistas` INT UNSIGNED NOT NULL DEFAULT 0,
  `creado_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `actualizado_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_visibilidad` (`visibilidad`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
