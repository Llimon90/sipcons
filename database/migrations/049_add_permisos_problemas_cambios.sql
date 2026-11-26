-- Migración 049: privilegios de los módulos nuevos (problemas, cambios)
--
-- Mismo mecanismo que la migración 034. Problemas es un módulo de análisis
-- técnico: Técnico/Supervisor/Administrador lo ven. Cambios es más sensible
-- (requiere aprobación formal), así que nace reservado a los roles de
-- administración; aprobar/rechazar además exige rol fijo (ver
-- backend/aprobar-cambio.php), no solo este permiso.
--
-- 100% aditiva. INSERT IGNORE: seguro de ejecutar más de una vez.
--
-- Cómo aplicar: igual que las migraciones anteriores, primero en apptest.

INSERT IGNORE INTO `permisos_rol` (`rol`, `modulo`, `permitido`) VALUES
('Administrador', 'problemas', 1),
('Administrador', 'cambios', 1),

('Técnico', 'problemas', 1),
('Técnico', 'cambios', 0),

('Supervisor', 'problemas', 1),
('Supervisor', 'cambios', 1),

('Administrativo', 'problemas', 0),
('Administrativo', 'cambios', 0),

('Técnico/Administrador', 'problemas', 1),
('Técnico/Administrador', 'cambios', 1);
