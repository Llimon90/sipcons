-- Migración 001: tabla de auditoría (historial de cambios)
--
-- Objetivo: registrar quién crea/edita/elimina registros en tablas
-- críticas (empezando por `incidencias`), sin tocar ninguna tabla
-- existente. Es 100% aditiva: solo crea una tabla nueva.
--
-- Seguridad para producción:
--   - Usa CREATE TABLE IF NOT EXISTS: se puede ejecutar más de una vez
--     sin error y sin duplicar nada.
--   - No modifica, renombra ni borra ninguna tabla existente.
--   - No tiene FOREIGN KEY hacia `usuarios`: si en el futuro se borra
--     un usuario, el historial de sus acciones se conserva intacto
--     (se guarda una copia de su nombre y rol en el momento del cambio).
--
-- Cómo aplicar: copiar y ejecutar este archivo completo en phpMyAdmin
-- (pestaña SQL) o por línea de comandos `mysql -u USUARIO -p BASE < 001_create_auditoria.sql`.
-- Recomendado: ejecutarlo primero en la base de datos de `apptest`
-- (entorno de pruebas) y verificar, antes de aplicarlo en producción.

CREATE TABLE IF NOT EXISTS `auditoria` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `usuario_id` INT NULL,
  `usuario_nombre` VARCHAR(150) NULL,
  `usuario_rol` VARCHAR(100) NULL,
  `accion` VARCHAR(20) NOT NULL,
  `tabla` VARCHAR(100) NOT NULL,
  `registro_id` VARCHAR(50) NULL,
  `datos_anteriores` LONGTEXT NULL,
  `datos_nuevos` LONGTEXT NULL,
  `ip_address` VARCHAR(45) NULL,
  `creado_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_tabla_registro` (`tabla`, `registro_id`),
  KEY `idx_usuario` (`usuario_id`),
  KEY `idx_creado_en` (`creado_en`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
