-- Migración 054: encuesta de satisfacción (CSAT) por incidencia cerrada.
-- Se genera automáticamente al cerrar (ver backend/actualiza.php y
-- backend/server.php). Se responde por token público, sin sesión: todavía
-- no hay SMTP en apptest, así que el enlace se escribe a
-- storage/logs/csat_pendientes.log para que el admin lo mande a mano.
--
-- 100% aditiva.
-- Cómo aplicar: igual que las migraciones anteriores, primero en apptest.

CREATE TABLE IF NOT EXISTS `encuestas_csat` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `incidencia_id` INT NOT NULL,
  `token` VARCHAR(64) NOT NULL,
  `calificacion` TINYINT UNSIGNED NULL,
  `comentario` TEXT NULL,
  `creado_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `respondido_en` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_incidencia` (`incidencia_id`),
  UNIQUE KEY `uniq_token` (`token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
