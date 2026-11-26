-- Migración 041: Gestión de Cambios (ITIL Change Management) con aprobación
--
-- Objetivo: un RFC (Request for Change) formal con folio propio (CAM-00001),
-- que necesita aprobación explícita (aprobador_id + fecha_aprobacion) antes
-- de pasar a 'Aprobado'. No se liga a ventas/incidencias por ahora: es un
-- registro de control de cambios independiente (ej. "actualizar firmware de
-- equipos de la sucursal X", "cambiar proveedor de internet").
--
-- 100% aditiva: solo crea una tabla nueva.
--
-- Cómo aplicar: igual que las migraciones anteriores, primero en apptest.

CREATE TABLE IF NOT EXISTS `cambios` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `folio` VARCHAR(20) NOT NULL,
  `titulo` VARCHAR(200) NOT NULL,
  `descripcion` TEXT NULL,
  `tipo` ENUM('Estándar','Normal','Emergencia') NOT NULL DEFAULT 'Normal',
  `riesgo` ENUM('Bajo','Medio','Alto') NOT NULL DEFAULT 'Medio',
  `estatus` ENUM('Pendiente','Aprobado','Rechazado','Implementado','Cerrado') NOT NULL DEFAULT 'Pendiente',
  `fecha_planeada` DATE NULL,
  `solicitante` VARCHAR(150) NULL,
  `aprobador_id` INT NULL,
  `aprobador` VARCHAR(150) NULL,
  `fecha_aprobacion` DATETIME NULL,
  `comentario_aprobacion` TEXT NULL,
  `notas` TEXT NULL,
  `creado_por` VARCHAR(150) NULL,
  `creado_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_folio_cambio` (`folio`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
