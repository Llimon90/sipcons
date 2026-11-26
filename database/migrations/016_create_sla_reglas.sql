-- Migración 016: reglas de SLA configurables (categoría × prioridad)
--
-- Antes backend/lib/estadisticas_helpers.php tenía el SLA fijo en código
-- (SLA_RESPUESTA_HORAS=4, SLA_CIERRE_HORAS=168). Esta tabla permite afinarlo
-- por categoría y prioridad desde Ajustes → Reglas de SLA, sin tocar código.
--
-- `categoria` NULL = regla "default" para esa prioridad, usada cuando no hay
-- una regla específica para la categoría de la incidencia. La semilla replica
-- los valores actuales (4h/168h) en las 4 prioridades para que el cambio sea
-- 100% transparente hasta que el Administrador decida afinar algo.
--
-- Cómo aplicar: igual que las migraciones anteriores, primero en apptest.

CREATE TABLE IF NOT EXISTS `sla_reglas` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `categoria` VARCHAR(100) NULL,
  `prioridad` ENUM('Baja','Media','Alta','Crítica') NOT NULL,
  `horas_respuesta` INT UNSIGNED NOT NULL,
  `horas_cierre` INT UNSIGNED NOT NULL,
  `activo` TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_categoria_prioridad` (`categoria`, `prioridad`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- INSERT IGNORE: seguro de ejecutar más de una vez, no pisa reglas que el
-- Administrador ya haya personalizado.
INSERT IGNORE INTO `sla_reglas` (`categoria`, `prioridad`, `horas_respuesta`, `horas_cierre`, `activo`) VALUES
(NULL, 'Baja',     4, 168, 1),
(NULL, 'Media',    4, 168, 1),
(NULL, 'Alta',     4, 168, 1),
(NULL, 'Crítica',  4, 168, 1);
