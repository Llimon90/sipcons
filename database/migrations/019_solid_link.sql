-- 019: Servidesk como sistema satélite de Solid Suite (D-50). Versión 2.20.0.
--
-- La cobranza, los planes y las vigencias de todos los sistemas viven en Solid Admin (app independiente).
-- Solid Admin le envía a Servidesk, por Solid Link (api/solid.php, docs/solid-link.md), el estado de cada
-- empresa: estado, vence (ya con la gracia), plan y límites. Aquí solo se guardan para aplicarlos:
--   empresas.limite_usuarios   límite de usuarios que decide el panel (NULL = el del plan local, como antes)
--   empresas.plan_externo      nombre/clave del plan en el panel (informativo)
-- Se puede ejecutar más de una vez. Revertir: 019_solid_link_revertir.sql

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'empresas' AND COLUMN_NAME = 'limite_usuarios') = 0,
  'ALTER TABLE `empresas` ADD COLUMN `limite_usuarios` int(11) DEFAULT NULL COMMENT ''Límite de usuarios que envía Solid Admin (NULL = el del plan)'' AFTER `precio`', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'empresas' AND COLUMN_NAME = 'plan_externo') = 0,
  'ALTER TABLE `empresas` ADD COLUMN `plan_externo` varchar(80) DEFAULT NULL COMMENT ''Plan en Solid Admin (informativo)'' AFTER `limite_usuarios`', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
