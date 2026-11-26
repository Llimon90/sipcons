-- Migración 055: privilegios de los módulos nuevos (catálogo de servicios,
-- base de conocimiento). Mismo mecanismo que la migración 004/034.
--
-- 100% aditiva. INSERT IGNORE: seguro de ejecutar más de una vez.
-- Cómo aplicar: igual que las migraciones anteriores, primero en apptest.

INSERT IGNORE INTO `permisos_rol` (`rol`, `modulo`, `permitido`) VALUES
('Administrador', 'catalogo_servicios', 1),
('Administrador', 'base_conocimiento', 1),

('Técnico', 'catalogo_servicios', 0),
('Técnico', 'base_conocimiento', 1),

('Administrativo', 'catalogo_servicios', 1),
('Administrativo', 'base_conocimiento', 1),

('Técnico/Administrativo', 'catalogo_servicios', 1),
('Técnico/Administrativo', 'base_conocimiento', 1),

('Técnico/Administrador', 'catalogo_servicios', 1),
('Técnico/Administrador', 'base_conocimiento', 1);
