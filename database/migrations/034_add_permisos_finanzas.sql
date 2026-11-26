-- Migración 034: privilegios de los nuevos módulos (inventario, cotizaciones,
-- cuentas por cobrar, finanzas)
--
-- Mismo mecanismo que la migración 004 (tabla permisos_rol). Valores iniciales
-- conservadores: Administrador y los roles mixtos de administración/ventas
-- reciben inventario/cotizaciones/cuentas_por_cobrar; el panel ejecutivo de
-- finanzas nace reservado solo a Administrador (y Programador, que siempre
-- tiene acceso total y no pasa por esta tabla). El propio Administrador puede
-- prender el resto desde Ajustes → Privilegios.
--
-- 100% aditiva. INSERT IGNORE: seguro de ejecutar más de una vez.
--
-- Cómo aplicar: igual que las migraciones anteriores, primero en apptest.

INSERT IGNORE INTO `permisos_rol` (`rol`, `modulo`, `permitido`) VALUES
('Administrador', 'inventario', 1),
('Administrador', 'cotizaciones', 1),
('Administrador', 'cuentas_por_cobrar', 1),
('Administrador', 'finanzas', 1),

('Técnico', 'inventario', 0),
('Técnico', 'cotizaciones', 0),
('Técnico', 'cuentas_por_cobrar', 0),
('Técnico', 'finanzas', 0),

('Administrativo', 'inventario', 1),
('Administrativo', 'cotizaciones', 1),
('Administrativo', 'cuentas_por_cobrar', 1),
('Administrativo', 'finanzas', 0),

('Supervisor', 'inventario', 1),
('Supervisor', 'cotizaciones', 1),
('Supervisor', 'cuentas_por_cobrar', 1),
('Supervisor', 'finanzas', 0),

('Técnico/Administrador', 'inventario', 1),
('Técnico/Administrador', 'cotizaciones', 1),
('Técnico/Administrador', 'cuentas_por_cobrar', 1),
('Técnico/Administrador', 'finanzas', 0);
