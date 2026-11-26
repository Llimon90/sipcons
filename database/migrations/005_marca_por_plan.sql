-- =====================================================================
--  Migración 005: personalización de marca según el plan
--
--  planes.permite_marca: si las empresas de ese plan pueden cambiar su logo,
--  colores y textos desde Mi empresa > Personalización. Se edita en el panel
--  (Planes y precios). Por defecto: Básico no, Profesional y Empresa sí.
--
--  Requiere la migración 002. Se puede importar más de una vez sin error.
-- =====================================================================

SET NAMES utf8mb4;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'planes' AND COLUMN_NAME = 'permite_marca') = 0,
  'ALTER TABLE `planes` ADD COLUMN `permite_marca` tinyint(1) NOT NULL DEFAULT ''1'' COMMENT ''1 = el cliente puede personalizar logo, colores y textos'' AFTER `max_usuarios`', 'DO 0');
SET @basico = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'planes' AND COLUMN_NAME = 'permite_marca');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Solo la primera vez (cuando la columna se acaba de crear): Básico sin personalización
SET @s = IF(@basico = 0, 'UPDATE `planes` SET `permite_marca` = 0 WHERE `clave` = ''basico''', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
