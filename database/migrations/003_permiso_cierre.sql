-- =====================================================================
--  Migración 003: permiso por usuario para cerrar incidencias
--
--  usuarios.puede_cerrar: el administrador de cada empresa decide en
--  Usuarios quién puede poner "Cerrado con factura" / "Cerrado sin factura"
--  (el rol Técnico nunca puede). Sustituye a la variable USUARIOS_SIN_CIERRE
--  del .env de la versión de una sola empresa, que en multiempresa no tendría dueño.
--
--  Requiere la migración 002. Se puede importar más de una vez sin error.
-- =====================================================================

SET NAMES utf8mb4;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'puede_cerrar') = 0,
  'ALTER TABLE `usuarios` ADD COLUMN `puede_cerrar` tinyint(1) NOT NULL DEFAULT ''1'' COMMENT ''0 = no puede poner Cerrado con/sin factura (lo decide el administrador de la empresa)'' AFTER `rol`', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
