-- Migración 011: analíticas de uso del sistema (panel privado protegido por token)
--
-- Objetivo: registrar cómo se usa el portal (páginas visitadas, tiempo activo,
-- clics, filtros, errores, latencia de la API) para tomar decisiones de diseño,
-- mejoras y correcciones. Los datos viven únicamente en esta base de datos;
-- no se envía nada a terceros.
--
-- Privacidad: NO se guarda el texto que se escribe en formularios ni valores de
-- campos de texto/contraseña; solo el nombre de la etiqueta del control, la
-- longitud de una búsqueda, o la opción elegida en listas desplegables.
-- La identidad (usuario/rol) la pone el servidor desde la sesión.
--
-- 100% aditiva: crea una tabla nueva. Mientras no exista, el sistema funciona
-- igual (el registro falla en silencio). Ejecutar una sola vez, primero en apptest.

CREATE TABLE IF NOT EXISTS `analytics_eventos` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `creado_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `usuario_id` INT NULL,
  `usuario` VARCHAR(100) NULL,
  `usuario_nombre` VARCHAR(150) NULL,
  `rol` VARCHAR(60) NULL,
  `sesion_id` VARCHAR(40) NOT NULL,
  `visita_id` VARCHAR(40) NOT NULL,
  `tipo` VARCHAR(24) NOT NULL,
  `pagina` VARCHAR(100) NULL,
  `modulo` VARCHAR(40) NULL,
  `elemento` VARCHAR(200) NULL,
  `detalle` VARCHAR(300) NULL,
  `valor` INT NULL,
  `extra` INT NULL,
  `referrer_pagina` VARCHAR(100) NULL,
  `dispositivo` VARCHAR(12) NULL,
  `navegador` VARCHAR(24) NULL,
  `sistema` VARCHAR(24) NULL,
  `viewport` VARCHAR(12) NULL,
  PRIMARY KEY (`id`),
  KEY `idx_an_creado` (`creado_en`),
  KEY `idx_an_usuario` (`usuario_id`, `creado_en`),
  KEY `idx_an_tipo` (`tipo`, `creado_en`),
  KEY `idx_an_sesion` (`sesion_id`),
  KEY `idx_an_visita` (`visita_id`),
  KEY `idx_an_pagina` (`pagina`, `tipo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
