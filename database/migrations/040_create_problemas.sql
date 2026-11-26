-- Migración 040: Gestión de Problemas (ITIL Problem Management)
--
-- Objetivo: agrupar incidencias recurrentes bajo una causa raíz común, en vez
-- de resolver cada una por separado sin ver el patrón. Un "problema" es una
-- entidad propia con su propio folio (PRB-00001, mismo estilo que
-- SIP-0001/VT-00001/COT-00001) y se le pueden vincular N incidencias ya
-- existentes (tabla puente problema_incidencias).
--
-- 100% aditiva: solo crea tablas nuevas.
--
-- Cómo aplicar: igual que las migraciones anteriores, primero en apptest.

CREATE TABLE IF NOT EXISTS `problemas` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `folio` VARCHAR(20) NOT NULL,
  `titulo` VARCHAR(200) NOT NULL,
  `descripcion` TEXT NULL,
  `causa_raiz` TEXT NULL,
  `estatus` ENUM('Abierto','En análisis','Resuelto','Cerrado') NOT NULL DEFAULT 'Abierto',
  `prioridad` ENUM('Baja','Media','Alta','Crítica') NOT NULL DEFAULT 'Media',
  `fecha_deteccion` DATE NOT NULL,
  `fecha_resolucion` DATE NULL,
  `creado_por` VARCHAR(150) NULL,
  `creado_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_folio_problema` (`folio`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `problema_incidencias` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `problema_id` INT UNSIGNED NOT NULL,
  `incidencia_id` INT NOT NULL,
  `vinculado_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_problema_incidencia` (`problema_id`, `incidencia_id`),
  KEY `idx_problema_incidencias_incidencia` (`incidencia_id`),
  CONSTRAINT `fk_pi_problema` FOREIGN KEY (`problema_id`) REFERENCES `problemas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
