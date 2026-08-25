-- Migración 004: privilegios configurables por rol
--
-- Objetivo: reemplazar el "todo o nada" (solo Administrador vs. resto) por
-- un checklist editable desde Ajustes: qué módulos puede usar cada rol.
--
-- 100% aditiva: solo crea una tabla nueva. No modifica ninguna existente.
-- El rol "Programador" NO se controla desde esta tabla: en el código
-- siempre tiene acceso total (modo mantenimiento), por eso no es
-- necesario darle de alta aquí, aunque no está de más tenerlo.
--
-- Valores iniciales: deliberadamente conservadores (mínimo privilegio).
-- El propio Administrador puede encender/apagar cualquier casilla desde
-- Ajustes → Privilegios en cuanto se despliegue este cambio.
--
-- Cómo aplicar: igual que las migraciones anteriores, primero en apptest.

CREATE TABLE IF NOT EXISTS `permisos_rol` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `rol` VARCHAR(50) NOT NULL,
  `modulo` VARCHAR(50) NOT NULL,
  `permitido` TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_rol_modulo` (`rol`, `modulo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- INSERT IGNORE: seguro de ejecutar más de una vez, no duplica ni pisa
-- valores que el Administrador ya haya personalizado.

-- Administrador: acceso total por defecto (igual que el comportamiento actual)
INSERT IGNORE INTO `permisos_rol` (`rol`, `modulo`, `permitido`) VALUES
('Administrador', 'incidencias', 1),
('Administrador', 'reportes', 1),
('Administrador', 'clientes', 1),
('Administrador', 'ventas', 1),
('Administrador', 'usuarios', 1),
('Administrador', 'soporte', 1),
('Administrador', 'informes', 1),
('Administrador', 'historial', 1);

-- Técnico: operación de campo (incidencias, consulta de incidencias, manuales)
INSERT IGNORE INTO `permisos_rol` (`rol`, `modulo`, `permitido`) VALUES
('Técnico', 'incidencias', 1),
('Técnico', 'reportes', 1),
('Técnico', 'clientes', 0),
('Técnico', 'ventas', 0),
('Técnico', 'usuarios', 0),
('Técnico', 'soporte', 1),
('Técnico', 'informes', 0),
('Técnico', 'historial', 0);

-- Administrativo: gestión comercial (clientes, ventas, estadísticas)
INSERT IGNORE INTO `permisos_rol` (`rol`, `modulo`, `permitido`) VALUES
('Administrativo', 'incidencias', 1),
('Administrativo', 'reportes', 1),
('Administrativo', 'clientes', 1),
('Administrativo', 'ventas', 1),
('Administrativo', 'usuarios', 0),
('Administrativo', 'soporte', 0),
('Administrativo', 'informes', 1),
('Administrativo', 'historial', 0);

-- Técnico/Administrativo: unión de Técnico + Administrativo
INSERT IGNORE INTO `permisos_rol` (`rol`, `modulo`, `permitido`) VALUES
('Técnico/Administrativo', 'incidencias', 1),
('Técnico/Administrativo', 'reportes', 1),
('Técnico/Administrativo', 'clientes', 1),
('Técnico/Administrativo', 'ventas', 1),
('Técnico/Administrativo', 'usuarios', 0),
('Técnico/Administrativo', 'soporte', 1),
('Técnico/Administrativo', 'informes', 1),
('Técnico/Administrativo', 'historial', 0);

-- Técnico/Administrador: mismo punto de partida que Técnico/Administrativo.
-- Usuarios e Historial quedan apagados a propósito: si este rol debe
-- gestionar usuarios, es una decisión explícita del Administrador desde
-- el checklist, no un valor por defecto.
INSERT IGNORE INTO `permisos_rol` (`rol`, `modulo`, `permitido`) VALUES
('Técnico/Administrador', 'incidencias', 1),
('Técnico/Administrador', 'reportes', 1),
('Técnico/Administrador', 'clientes', 1),
('Técnico/Administrador', 'ventas', 1),
('Técnico/Administrador', 'usuarios', 0),
('Técnico/Administrador', 'soporte', 1),
('Técnico/Administrador', 'informes', 1),
('Técnico/Administrador', 'historial', 0);
