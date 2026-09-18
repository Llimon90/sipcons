-- Migración 006: acceso total para el rol "Técnico/Administrador"
--
-- Objetivo: que "Técnico/Administrador" tenga control total, igual que
-- "Administrador" (todos los módulos habilitados en permisos_rol).
--
-- Idempotente: puede ejecutarse más de una vez sin efectos secundarios.
--
-- Cómo aplicar: igual que las migraciones anteriores, primero en apptest.

INSERT INTO `permisos_rol` (`rol`, `modulo`, `permitido`) VALUES
('Técnico/Administrador', 'incidencias', 1),
('Técnico/Administrador', 'reportes', 1),
('Técnico/Administrador', 'clientes', 1),
('Técnico/Administrador', 'ventas', 1),
('Técnico/Administrador', 'usuarios', 1),
('Técnico/Administrador', 'soporte', 1),
('Técnico/Administrador', 'informes', 1),
('Técnico/Administrador', 'historial', 1)
ON DUPLICATE KEY UPDATE `permitido` = 1;
