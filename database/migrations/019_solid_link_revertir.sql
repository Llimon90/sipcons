-- Revierte 019_solid_link.sql (Servidesk vuelve a administrarse solo desde su panel /plataforma).
ALTER TABLE `empresas` DROP COLUMN `plan_externo`, DROP COLUMN `limite_usuarios`;
