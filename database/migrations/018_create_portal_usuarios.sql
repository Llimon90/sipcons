-- Migración 018: usuarios del portal de autoservicio de clientes
--
-- Acceso 100% separado del login de personal (usuarios/config/auth.php):
-- es una tabla y una sesión propias (ver auth/portal_middleware.php), para
-- que una cuenta de portal nunca pueda alcanzar nada del sistema interno y
-- viceversa. `contacto_id` es informativo (de qué contacto del cliente es
-- este acceso); el acceso en sí vive aquí, no en cliente_contactos.
--
-- Cómo aplicar: igual que las migraciones anteriores, primero en apptest.

CREATE TABLE IF NOT EXISTS `portal_usuarios` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `cliente_id` INT UNSIGNED NOT NULL,
  `contacto_id` INT UNSIGNED NULL,
  `email` VARCHAR(190) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `activo` TINYINT(1) NOT NULL DEFAULT 1,
  `intentos_fallidos` INT UNSIGNED NOT NULL DEFAULT 0,
  `bloqueado_hasta` DATETIME NULL,
  `token_reset` VARCHAR(100) NULL,
  `token_reset_expira` DATETIME NULL,
  `creado_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ultimo_acceso` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_portal_email` (`email`),
  KEY `idx_portal_cliente` (`cliente_id`),
  CONSTRAINT `fk_portal_cliente` FOREIGN KEY (`cliente_id`) REFERENCES `clientes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_portal_contacto` FOREIGN KEY (`contacto_id`) REFERENCES `cliente_contactos` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
