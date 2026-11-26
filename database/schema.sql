-- =====================================================================
--  Servidesk - esquema base (multiempresa)
--  GENERADO por database/tools/generar_schema.py (no editar a mano;
--  las tablas de empresa se editan en database/plantilla_empresa.sql).
--
--  Instalación nueva (phpMyAdmin o consola):
--    1) database/schema.sql  -> plataforma + empresa 1 (demo) vacía
--    2) database/seed.sql    -> (opcional) datos de la empresa demo
--  Las demás empresas se crean desde la aplicación (registro o panel).
--  Compatible con MySQL 5.7+ / MariaDB 10.3+. Todo en utf8mb4.
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- Tablas centrales de la plataforma (sin prefijo)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `empresas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `slug` varchar(60) NOT NULL COMMENT 'Identificador corto y único (acme)',
  `nombre` varchar(150) NOT NULL,
  `estado` varchar(20) NOT NULL DEFAULT 'prueba' COMMENT 'demo | prueba | activa | suspendida | cancelada',
  `prueba_hasta` date DEFAULT NULL,
  `pagado_hasta` date DEFAULT NULL COMMENT 'Último día cubierto por los pagos (estado activa)',
  `verificacion_hasta` date DEFAULT NULL COMMENT 'Acceso provisional mientras la plataforma verifica un pago',
  `marca` longtext COMMENT 'JSON: nombre, eslogan, logo, colores, categorias, prefijo_folio, zona_horaria',
  `contacto_nombre` varchar(150) DEFAULT NULL,
  `contacto_correo` varchar(150) DEFAULT NULL,
  `contacto_telefono` varchar(50) DEFAULT NULL,
  `notas` text,
  `registro_ip` varchar(45) DEFAULT NULL COMMENT 'IP desde la que se registró (autoservicio)',
  `plan_id` int(11) DEFAULT NULL,
  `ciclo` varchar(10) NOT NULL DEFAULT 'mensual' COMMENT 'mensual | anual',
  `precio` decimal(12,2) DEFAULT NULL COMMENT 'Precio pactado por ciclo; NULL = precio del plan',
  `limite_usuarios` int(11) DEFAULT NULL COMMENT 'Límite de usuarios que envía Solid Admin (NULL = el del plan)',
  `plan_externo` varchar(80) DEFAULT NULL COMMENT 'Plan en Solid Admin (informativo)',
  `rfc` varchar(20) DEFAULT NULL,
  `razon_social` varchar(200) DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `actualizado_en` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_slug` (`slug`),
  KEY `idx_registro_ip` (`registro_ip`, `creado_en`),
  KEY `idx_plan` (`plan_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Catálogo de planes (precios y límites que se aplican a cada empresa)
CREATE TABLE IF NOT EXISTS `planes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `clave` varchar(30) NOT NULL,
  `nombre` varchar(80) NOT NULL,
  `descripcion` varchar(255) DEFAULT NULL,
  `precio_mensual` decimal(12,2) NOT NULL DEFAULT '0.00',
  `precio_anual` decimal(12,2) NOT NULL DEFAULT '0.00',
  `max_usuarios` int(11) DEFAULT NULL COMMENT 'NULL = sin límite',
  `permite_marca` tinyint(1) NOT NULL DEFAULT '1' COMMENT '1 = el cliente puede personalizar logo, colores y textos',
  `activo` tinyint(1) NOT NULL DEFAULT '1',
  `orden` int(11) NOT NULL DEFAULT '0',
  `creado_en` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_clave` (`clave`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Notas internas de seguimiento comercial de cada empresa (no las ve el cliente)
CREATE TABLE IF NOT EXISTS `empresa_notas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `empresa_id` int(11) NOT NULL,
  `nota` text NOT NULL,
  `autor` varchar(150) DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_empresa` (`empresa_id`, `creado_en`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ajustes de negocio editables desde el panel (sustituyen a los valores por defecto)
CREATE TABLE IF NOT EXISTS `plataforma_config` (
  `clave` varchar(60) NOT NULL,
  `valor` text,
  `actualizado_en` datetime DEFAULT NULL,
  PRIMARY KEY (`clave`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `usuarios` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `empresa_id` int(11) DEFAULT NULL COMMENT 'NULL = superadministrador de la plataforma',
  `nombre` varchar(100) NOT NULL,
  `alias_tecnico` varchar(150) DEFAULT NULL COMMENT 'Nombre exacto tal como aparece en incidencias.tecnico, si difiere de usuarios.nombre',
  `asistente` varchar(20) DEFAULT NULL COMMENT 'Asistente guiado: NULL = pendiente, cerrado, terminado (D-20)',
  `preferencias` text DEFAULT NULL COMMENT 'Preferencias personales en JSON (O-15)',
  `foto` varchar(255) DEFAULT NULL COMMENT 'Foto de perfil (uploads/e<ID>/usuarios/...); D-46',
  `debe_cambiar_password` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = contraseña temporal del panel; se cambia al entrar (L-13)',
  `correo` varchar(100) NOT NULL,
  `telefono` varchar(20) NOT NULL,
  `usuario` varchar(100) NOT NULL COMMENT 'Único en toda la plataforma (normalmente el correo)',
  `password` varchar(255) NOT NULL,
  `rol` varchar(50) NOT NULL,
  `puede_cerrar` tinyint(1) NOT NULL DEFAULT '1' COMMENT '0 = no puede poner Cerrado con/sin factura (lo decide el administrador de la empresa)',
  `puede_autorizar_descuentos` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1 = puede rebasar el descuento máximo en cotizaciones',
  `fecha_creacion` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `tema` varchar(10) NOT NULL DEFAULT 'claro' COMMENT 'Tema de la interfaz: claro | oscuro',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_usuario` (`usuario`),
  UNIQUE KEY `uniq_empresa_nombre` (`empresa_id`, `nombre`),
  KEY `idx_empresa` (`empresa_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `pagos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `empresa_id` int(11) NOT NULL,
  `tipo` varchar(20) NOT NULL DEFAULT 'pago' COMMENT 'pago | abono | nota_credito',
  `monto` decimal(12,2) NOT NULL DEFAULT '0.00',
  `fecha_pago` date NOT NULL,
  `cubre_hasta` date NOT NULL COMMENT 'Nuevo pagado_hasta de la empresa',
  `metodo` varchar(40) DEFAULT NULL,
  `referencia` varchar(100) DEFAULT NULL,
  `notas` varchar(255) DEFAULT NULL,
  `factura` varchar(60) DEFAULT NULL COMMENT 'Folio o UUID del CFDI emitido',
  `registrado_por` varchar(150) DEFAULT NULL,
  `anulado` tinyint(1) NOT NULL DEFAULT '0',
  `anulado_motivo` varchar(255) DEFAULT NULL,
  `anulado_en` datetime DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_empresa` (`empresa_id`, `fecha_pago`),
  KEY `idx_fecha` (`fecha_pago`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Intentos fallidos de inicio de sesión (bloqueo temporal, auth/login.php)
CREATE TABLE IF NOT EXISTS `login_intentos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `usuario` varchar(190) NOT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_usuario` (`usuario`, `creado_en`),
  KEY `idx_ip` (`ip`, `creado_en`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Solicitudes de contratación por transferencia (las confirma la plataforma)
CREATE TABLE IF NOT EXISTS `solicitudes_pago` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `empresa_id` int(11) NOT NULL,
  `plan_id` int(11) NOT NULL,
  `ciclo` varchar(10) NOT NULL DEFAULT 'mensual' COMMENT 'mensual | anual',
  `monto` decimal(12,2) NOT NULL,
  `moneda` varchar(3) NOT NULL DEFAULT 'MXN',
  `referencia` varchar(30) DEFAULT NULL COMMENT 'Concepto que el cliente pone en su transferencia',
  `estado` varchar(15) NOT NULL DEFAULT 'pendiente' COMMENT 'pendiente | confirmada | rechazada | cancelada',
  `requiere_factura` tinyint(1) NOT NULL DEFAULT '0',
  `rfc` varchar(13) DEFAULT NULL,
  `razon_social` varchar(200) DEFAULT NULL,
  `regimen_fiscal` varchar(3) DEFAULT NULL COMMENT 'Clave del SAT (601, 612, 626...)',
  `codigo_postal` varchar(5) DEFAULT NULL COMMENT 'Domicilio fiscal',
  `uso_cfdi` varchar(4) DEFAULT NULL,
  `correo_factura` varchar(150) DEFAULT NULL,
  `comprobante` varchar(255) DEFAULT NULL COMMENT 'Archivo en storage/comprobantes/e<ID>/',
  `comprobante_nombre` varchar(255) DEFAULT NULL COMMENT 'Nombre original del archivo',
  `acceso_hasta` date DEFAULT NULL COMMENT 'Acceso provisional que se otorgó al solicitar',
  `motivo` varchar(255) DEFAULT NULL COMMENT 'Motivo del rechazo',
  `pago_id` int(11) DEFAULT NULL COMMENT 'Pago registrado al confirmar',
  `usuario_id` int(11) DEFAULT NULL,
  `solicitado_por` varchar(150) DEFAULT NULL,
  `resuelto_por` varchar(150) DEFAULT NULL,
  `resuelto_en` datetime DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_empresa` (`empresa_id`, `creado_en`),
  KEY `idx_estado` (`estado`, `creado_en`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bitácora de acciones de plataforma (altas, pagos, extensiones, suspensiones...)
CREATE TABLE IF NOT EXISTS `plataforma_eventos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `empresa_id` int(11) DEFAULT NULL,
  `accion` varchar(40) NOT NULL,
  `detalle` text COMMENT 'JSON con los datos de la acción',
  `usuario_nombre` varchar(150) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_empresa` (`empresa_id`, `creado_en`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `analytics_eventos` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `empresa_id` int(11) DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `usuario_id` int(11) DEFAULT NULL,
  `usuario` varchar(100) DEFAULT NULL,
  `usuario_nombre` varchar(150) DEFAULT NULL,
  `rol` varchar(60) DEFAULT NULL,
  `sesion_id` varchar(40) NOT NULL,
  `visita_id` varchar(40) NOT NULL,
  `tipo` varchar(24) NOT NULL,
  `pagina` varchar(100) DEFAULT NULL,
  `modulo` varchar(40) DEFAULT NULL,
  `elemento` varchar(200) DEFAULT NULL,
  `detalle` varchar(300) DEFAULT NULL,
  `valor` int(11) DEFAULT NULL,
  `extra` int(11) DEFAULT NULL,
  `referrer_pagina` varchar(100) DEFAULT NULL,
  `dispositivo` varchar(12) DEFAULT NULL,
  `navegador` varchar(24) DEFAULT NULL,
  `sistema` varchar(24) DEFAULT NULL,
  `viewport` varchar(12) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_an_creado` (`creado_en`),
  KEY `idx_an_empresa` (`empresa_id`, `creado_en`),
  KEY `idx_an_usuario` (`usuario_id`,`creado_en`),
  KEY `idx_an_tipo` (`tipo`,`creado_en`),
  KEY `idx_an_sesion` (`sesion_id`),
  KEY `idx_an_visita` (`visita_id`),
  KEY `idx_an_pagina` (`pagina`,`tipo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Empresa 1: demo pública (estado 'demo', se reinicia cada noche)
-- ---------------------------------------------------------------------
-- Planes iniciales (editables en el panel de plataforma > Planes)
INSERT IGNORE INTO `planes` (`id`, `clave`, `nombre`, `descripcion`, `precio_mensual`, `precio_anual`, `max_usuarios`, `permite_marca`, `orden`) VALUES
(1, 'basico', 'Básico', 'Para talleres y equipos pequeños', 690.00, 6900.00, 5, 1, 1),
(2, 'profesional', 'Profesional', 'Para empresas de servicio en crecimiento', 1490.00, 14900.00, 15, 1, 2),
(3, 'empresa', 'Empresa', 'Sin límite de usuarios', 2990.00, 29900.00, NULL, 1, 3);

INSERT IGNORE INTO `empresas` (`id`, `slug`, `nombre`, `estado`, `plan_id`, `marca`) VALUES
(1, 'demo', 'Servidesk Demo', 'demo', 3, '{"nombre": "Servidesk", "eslogan": "Gestión de servicios y mantenimiento", "logo": "img/logo.svg", "colores": {"primario": "#3498db", "secundario": "#1a4de4", "sidebar": "#2c3e50"}, "categorias": ["Mantenimiento correctivo", "Mantenimiento preventivo", "Instalación", "Garantía", "Diagnóstico", "Otros"], "prefijo_folio": "SD", "zona_horaria": "America/Mexico_City"}');

CREATE TABLE IF NOT EXISTS `e1_clientes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(100) NOT NULL,
  `rfc` varchar(20) NOT NULL,
  `direccion` varchar(150) NOT NULL,
  `telefono` varchar(20) NOT NULL,
  `contactos` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `fecha_registro` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `dias_credito` int(11) NOT NULL DEFAULT '0' COMMENT 'Días de crédito para el vencimiento de sus cuentas (0 = contado)',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `e1_cliente_contactos` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `cliente_id` int(11) NOT NULL,
  `nombre` varchar(150) NOT NULL,
  `telefono` varchar(50) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_cliente_id` (`cliente_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `e1_incidencias` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `reporte_cliente` varchar(100) DEFAULT NULL,
  `numero_incidente` varchar(100) DEFAULT NULL,
  `cliente` varchar(255) DEFAULT NULL,
  `contacto` varchar(255) DEFAULT NULL,
  `sucursal` varchar(255) DEFAULT NULL,
  `fecha` date DEFAULT NULL,
  `tecnico` varchar(255) DEFAULT NULL,
  `falla` text,
  `categoria` varchar(50) NOT NULL,
  `numero_serie` varchar(100) DEFAULT NULL COMMENT 'Número de serie del equipo atendido (para detectar reincidencias)',
  `estatus` varchar(100) DEFAULT NULL,
  `motivo_estatus` varchar(255) DEFAULT NULL COMMENT 'Motivo del estatus actual (Pendiente o Cancelado)',
  `accion` text,
  `notas` text,
  `clave_envio` varchar(64) DEFAULT NULL COMMENT 'Clave única del formulario de alta (evita el doble envío)',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_numero_incidente` (`numero_incidente`),
  UNIQUE KEY `uniq_clave_envio` (`clave_envio`),
  KEY `idx_incidencias_numero_serie` (`numero_serie`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Contador de folios por tipo de documento: nunca retrocede (migración 011)
CREATE TABLE IF NOT EXISTS `e1_folios` (
  `clave` varchar(30) NOT NULL,
  `ultimo` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`clave`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `e1_archivos_incidencias` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `incidencia_id` int(11) NOT NULL,
  `ruta_archivo` varchar(255) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_incidencia` (`incidencia_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `e1_marcas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(100) NOT NULL,
  `fecha_creacion` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `fecha_eliminacion` timestamp NULL DEFAULT NULL,
  `activo` tinyint(4) DEFAULT '1',
  PRIMARY KEY (`id`),
  UNIQUE KEY `nombre` (`nombre`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `e1_modelos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `marca_id` int(11) NOT NULL,
  `nombre` varchar(100) NOT NULL,
  `tipo_equipo` varchar(100) NOT NULL,
  `fecha_creacion` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `fecha_eliminacion` timestamp NULL DEFAULT NULL,
  `activo` tinyint(4) DEFAULT '1',
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_modelo_marca` (`marca_id`,`nombre`),
  FOREIGN KEY (`marca_id`) REFERENCES `e1_marcas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `e1_documentos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `modelo_id` int(11) NOT NULL,
  `tipo_documento` enum('manual','lista_partes','tutorial','imagen','diagrama','esquema','firmware','software','otro') NOT NULL,
  `nombre_archivo` varchar(255) NOT NULL,
  `descripcion` text,
  `ruta_archivo` varchar(500) NOT NULL,
  `fecha_subida` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `modelo_id` (`modelo_id`),
  FOREIGN KEY (`modelo_id`) REFERENCES `e1_modelos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `e1_inventario_articulos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `sku` varchar(40) NOT NULL,
  `nombre` varchar(150) NOT NULL,
  `categoria` varchar(30) NOT NULL COMMENT 'Equipo | Refacción | Consumible | Herramienta | Servicio | Mano de obra',
  `controla_existencia` tinyint(1) NOT NULL DEFAULT '1' COMMENT '0 = servicio o mano de obra: sin existencias',
  `marca` varchar(100) DEFAULT NULL,
  `modelo` varchar(100) DEFAULT NULL,
  `descripcion` text,
  `unidad` varchar(20) NOT NULL DEFAULT 'pza',
  `existencia` decimal(12,2) NOT NULL DEFAULT '0.00',
  `stock_minimo` decimal(12,2) NOT NULL DEFAULT '0.00',
  `costo_unitario` decimal(12,2) NOT NULL DEFAULT '0.00',
  `precio_venta` decimal(12,2) NOT NULL DEFAULT '0.00',
  `ubicacion` varchar(100) DEFAULT NULL,
  `imagen` varchar(255) DEFAULT NULL COMMENT 'Foto del producto (uploads/e<ID>/inventario/...)',
  `activo` tinyint(1) NOT NULL DEFAULT '1',
  `creado_en` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `actualizado_en` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_sku` (`sku`),
  KEY `idx_categoria` (`categoria`,`activo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `e1_inventario_movimientos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `articulo_id` int(11) NOT NULL,
  `tipo` varchar(10) NOT NULL COMMENT 'entrada | salida | ajuste',
  `cantidad` decimal(12,2) NOT NULL,
  `existencia_resultante` decimal(12,2) NOT NULL,
  `costo_unitario` decimal(12,2) DEFAULT NULL,
  `precio_unitario` decimal(12,2) DEFAULT NULL COMMENT 'Precio de venta del día del movimiento (D-51)',
  `motivo` varchar(255) DEFAULT NULL,
  `referencia_tipo` varchar(20) DEFAULT NULL COMMENT 'incidencia | venta | manual',
  `referencia_id` int(11) DEFAULT NULL,
  `referencia_folio` varchar(50) DEFAULT NULL,
  `usuario_id` int(11) DEFAULT NULL,
  `usuario_nombre` varchar(150) DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_articulo` (`articulo_id`,`creado_en`),
  KEY `idx_referencia` (`referencia_tipo`,`referencia_id`),
  KEY `idx_creado` (`creado_en`),
  FOREIGN KEY (`articulo_id`) REFERENCES `e1_inventario_articulos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `e1_ventas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `folio` varchar(20) NOT NULL,
  `cliente` varchar(100) NOT NULL,
  `fecha_registro` date NOT NULL,
  `fecha_actualizacion` date DEFAULT NULL,
  `sucursal` varchar(100) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_folio` (`folio`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `e1_venta_detalles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `venta_id` int(11) NOT NULL,
  `articulo_id` int(11) DEFAULT NULL COMMENT 'Artículo de inventario vendido (descuenta existencia)',
  `equipo` varchar(100) DEFAULT NULL,
  `marca` varchar(100) DEFAULT NULL,
  `modelo` varchar(100) DEFAULT NULL,
  `numero_serie` varchar(100) NOT NULL,
  `garantia` int(11) DEFAULT NULL,
  `calibracion` int(11) DEFAULT '0',
  `servicio` tinyint(1) DEFAULT '0',
  `frecuencia_servicio` int(11) DEFAULT '0',
  `notas` text,
  `proxima_calibracion` date DEFAULT NULL,
  `proximo_servicio` date DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `venta_id` (`venta_id`),
  KEY `idx_articulo` (`articulo_id`),
  FOREIGN KEY (`venta_id`) REFERENCES `e1_ventas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `e1_venta_archivos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `venta_id` int(11) NOT NULL,
  `ruta_archivo` varchar(255) NOT NULL,
  `nombre_archivo` varchar(255) DEFAULT NULL,
  `tipo_archivo` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `venta_id` (`venta_id`),
  FOREIGN KEY (`venta_id`) REFERENCES `e1_ventas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `e1_archivos_ventas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `venta_id` int(11) NOT NULL,
  `nombre` varchar(255) NOT NULL,
  `nombre_original` varchar(255) NOT NULL,
  `tipo` varchar(100) NOT NULL,
  `tamano` int(11) NOT NULL,
  `ruta` varchar(255) NOT NULL,
  `fecha_subida` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `venta_id` (`venta_id`),
  FOREIGN KEY (`venta_id`) REFERENCES `e1_ventas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `e1_padron_equipos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `cliente` varchar(255) NOT NULL,
  `sucursal` varchar(255) DEFAULT NULL,
  `equipo` varchar(255) NOT NULL,
  `marca` varchar(255) DEFAULT NULL,
  `modelo` varchar(255) DEFAULT NULL,
  `numero_serie` varchar(100) DEFAULT NULL,
  `calibracion` int(11) DEFAULT '0',
  `servicio` tinyint(1) DEFAULT '0',
  `frecuencia_servicio` int(11) DEFAULT '0',
  `garantia` int(11) DEFAULT '0',
  `proxima_calibracion` date DEFAULT NULL,
  `proximo_servicio` date DEFAULT NULL,
  `origen` varchar(50) DEFAULT 'Externo' COMMENT 'Externo | Venta propia',
  `venta_id` int(11) DEFAULT NULL,
  `venta_detalle_id` int(11) DEFAULT NULL,
  `fecha_registro` date DEFAULT NULL,
  `reemplazo_incidencia_id` int(11) DEFAULT NULL COMMENT 'Orden de servicio donde se instaló como reemplazo',
  `reemplazo_articulo_id` int(11) DEFAULT NULL COMMENT 'Artículo de inventario instalado',
  PRIMARY KEY (`id`),
  KEY `idx_venta_detalle` (`venta_detalle_id`),
  KEY `idx_reemplazo` (`reemplazo_incidencia_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Servicios y mano de obra aplicados en una orden: no tienen existencias (migración 015, D-23)
CREATE TABLE IF NOT EXISTS `e1_incidencia_servicios` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `incidencia_id` int(11) NOT NULL,
  `articulo_id` int(11) NOT NULL,
  `descripcion` varchar(255) NOT NULL,
  `unidad` varchar(30) DEFAULT NULL,
  `cantidad` decimal(12,2) NOT NULL DEFAULT '1.00',
  `precio_unitario` decimal(14,2) NOT NULL DEFAULT '0.00',
  `creado_por` int(11) DEFAULT NULL,
  `creado_por_nombre` varchar(150) DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_incidencia` (`incidencia_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `e1_auditoria` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `usuario_id` int(11) DEFAULT NULL,
  `usuario_nombre` varchar(150) DEFAULT NULL,
  `usuario_rol` varchar(100) DEFAULT NULL,
  `accion` varchar(20) NOT NULL,
  `tabla` varchar(100) NOT NULL,
  `registro_id` varchar(50) DEFAULT NULL,
  `registro_folio` varchar(50) DEFAULT NULL,
  `datos_anteriores` longtext,
  `datos_nuevos` longtext,
  `ip_address` varchar(45) DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_tabla_registro` (`tabla`,`registro_id`),
  KEY `idx_usuario` (`usuario_id`),
  KEY `idx_creado_en` (`creado_en`),
  KEY `idx_registro_folio` (`registro_folio`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `e1_permisos_rol` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `rol` varchar(50) NOT NULL,
  `modulo` varchar(50) NOT NULL,
  `permitido` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_rol_modulo` (`rol`,`modulo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cotizaciones (Fase 1). Las partidas salen del catálogo (inventario_articulos)
-- con precio y costo congelados; la cotización nunca mueve existencias.
CREATE TABLE IF NOT EXISTS `e1_cotizaciones` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `folio` varchar(30) NOT NULL,
  `folio_base` varchar(20) NOT NULL COMMENT 'Folio de la serie (COT-0001); las versiones agregan -v2, -v3',
  `version` int(11) NOT NULL DEFAULT '1',
  `cotizacion_origen_id` int(11) DEFAULT NULL COMMENT 'Primera versión de la serie',
  `reemplazada_por` int(11) DEFAULT NULL COMMENT 'Versión que la sustituye (queda solo de consulta)',
  `cliente_id` int(11) DEFAULT NULL,
  `cliente_nombre` varchar(255) NOT NULL,
  `sucursal` varchar(255) DEFAULT NULL,
  `contacto_id` int(10) UNSIGNED DEFAULT NULL,
  `contacto_nombre` varchar(150) DEFAULT NULL,
  `incidencia_id` int(11) DEFAULT NULL,
  `venta_id` int(11) DEFAULT NULL COMMENT 'Primera venta registrada desde la cotización',
  `estatus` varchar(20) NOT NULL DEFAULT 'borrador' COMMENT 'borrador | enviada | aprobada | rechazada | vencida | convertida',
  `aprobacion_parcial` tinyint(1) NOT NULL DEFAULT '0',
  `fecha` date NOT NULL,
  `vigencia_hasta` date NOT NULL,
  `moneda` char(3) NOT NULL DEFAULT 'MXN',
  `tipo_cambio` decimal(12,4) NOT NULL DEFAULT '1.0000',
  `precios_incluyen_iva` tinyint(1) NOT NULL DEFAULT '0',
  `condiciones` text,
  `notas_cliente` text,
  `notas_internas` text,
  `subtotal` decimal(14,2) NOT NULL DEFAULT '0.00',
  `descuento_total` decimal(14,2) NOT NULL DEFAULT '0.00',
  `iva_total` decimal(14,2) NOT NULL DEFAULT '0.00',
  `total` decimal(14,2) NOT NULL DEFAULT '0.00',
  `total_aprobado` decimal(14,2) DEFAULT NULL COMMENT 'Total de las partidas que el cliente aceptó',
  `motivo_rechazo` varchar(255) DEFAULT NULL,
  `aprobada_por_nombre` varchar(150) DEFAULT NULL,
  `fecha_aprobacion` datetime DEFAULT NULL,
  `fecha_envio` datetime DEFAULT NULL,
  `token_publico` varchar(64) DEFAULT NULL COMMENT 'Fase 2: enlace público de aprobación',
  `creado_por` int(11) DEFAULT NULL,
  `creado_por_nombre` varchar(150) DEFAULT NULL,
  `actualizado_por` int(11) DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `actualizado_en` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_folio` (`folio`),
  UNIQUE KEY `uniq_token` (`token_publico`),
  KEY `idx_estatus` (`estatus`, `vigencia_hasta`),
  KEY `idx_cliente` (`cliente_id`),
  KEY `idx_incidencia` (`incidencia_id`),
  KEY `idx_serie` (`folio_base`, `version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `e1_cotizacion_partidas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `cotizacion_id` int(11) NOT NULL,
  `orden` int(11) NOT NULL DEFAULT '0',
  `tipo` varchar(20) NOT NULL COMMENT 'equipo | refaccion | servicio | mano_obra | poliza',
  `articulo_id` int(11) DEFAULT NULL COMMENT 'Artículo o servicio del catálogo (inventario_articulos)',
  `sku` varchar(40) DEFAULT NULL,
  `descripcion` varchar(255) NOT NULL,
  `marca` varchar(100) DEFAULT NULL,
  `modelo` varchar(100) DEFAULT NULL,
  `unidad` varchar(20) NOT NULL DEFAULT 'pza',
  `cantidad` decimal(12,2) NOT NULL DEFAULT '1.00',
  `precio_unitario` decimal(14,2) NOT NULL DEFAULT '0.00' COMMENT 'Congelado al cotizar, en la moneda de la cotización',
  `descuento_pct` decimal(5,2) NOT NULL DEFAULT '0.00',
  `tasa_iva` decimal(4,2) NOT NULL DEFAULT '0.16',
  `es_exento` tinyint(1) NOT NULL DEFAULT '0',
  `costo_unitario` decimal(14,2) NOT NULL DEFAULT '0.00' COMMENT 'Copia del costo al cotizar (margen); nunca se muestra al cliente',
  `importe` decimal(14,2) NOT NULL DEFAULT '0.00' COMMENT 'Sin IVA, después del descuento',
  `descuento_importe` decimal(14,2) NOT NULL DEFAULT '0.00',
  `iva` decimal(14,2) NOT NULL DEFAULT '0.00',
  `opcional` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Fase 3',
  `aceptada` tinyint(1) DEFAULT NULL COMMENT 'NULL = sin decidir; 1/0 al aprobar',
  `cargada_incidencia` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Refacción ya registrada en la incidencia ligada',
  `incluye_congelado` text DEFAULT NULL COMMENT 'Incluye del servicio al enviar (L-9)',
  PRIMARY KEY (`id`),
  KEY `idx_cotizacion` (`cotizacion_id`, `orden`),
  KEY `idx_articulo` (`articulo_id`),
  CONSTRAINT `fk_e1_partida_cotizacion` FOREIGN KEY (`cotizacion_id`) REFERENCES `e1_cotizaciones` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ventas por registrar que deja una cotización aprobada (una por modelo de equipo)
CREATE TABLE IF NOT EXISTS `e1_cotizacion_ventas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `cotizacion_id` int(11) NOT NULL,
  `articulo_id` int(11) DEFAULT NULL,
  `descripcion` varchar(255) NOT NULL,
  `marca` varchar(100) DEFAULT NULL,
  `modelo` varchar(100) DEFAULT NULL,
  `cantidad` decimal(12,2) NOT NULL DEFAULT '1.00',
  `estado` varchar(20) NOT NULL DEFAULT 'por_registrar' COMMENT 'por_registrar | registrada | descartada',
  `venta_id` int(11) DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `actualizado_en` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_estado` (`estado`),
  KEY `idx_cotizacion` (`cotizacion_id`),
  CONSTRAINT `fk_e1_cventa_cotizacion` FOREIGN KEY (`cotizacion_id`) REFERENCES `e1_cotizaciones` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Finanzas: lo que deben los clientes, lo que pagan y los gastos. La factura
-- se emite fuera del sistema (aquí solo su folio). Nada se borra: se cancela.
CREATE TABLE IF NOT EXISTS `e1_cuentas_cobrar` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `folio` varchar(20) NOT NULL,
  `cliente_id` int(11) DEFAULT NULL,
  `cliente_nombre` varchar(255) NOT NULL,
  `concepto` varchar(255) NOT NULL,
  `origen_tipo` varchar(20) DEFAULT NULL COMMENT 'cotizacion | incidencia | venta',
  `origen_id` int(11) DEFAULT NULL,
  `origen_folio` varchar(100) DEFAULT NULL,
  `fecha` date NOT NULL,
  `vencimiento` date NOT NULL,
  `subtotal` decimal(14,2) NOT NULL DEFAULT '0.00',
  `iva` decimal(14,2) NOT NULL DEFAULT '0.00',
  `retenciones` decimal(14,2) NOT NULL DEFAULT '0.00',
  `total` decimal(14,2) NOT NULL DEFAULT '0.00',
  `cobrado` decimal(14,2) NOT NULL DEFAULT '0.00',
  `factura` varchar(100) DEFAULT NULL COMMENT 'Folio o UUID de la factura emitida fuera del sistema',
  `estatus` varchar(15) NOT NULL DEFAULT 'pendiente' COMMENT 'pendiente | parcial | pagada | cancelada',
  `motivo_cancelacion` varchar(255) DEFAULT NULL,
  `notas` text,
  `creado_por` int(11) DEFAULT NULL,
  `creado_por_nombre` varchar(150) DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `actualizado_en` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_folio` (`folio`),
  KEY `idx_cliente` (`cliente_id`),
  KEY `idx_estatus` (`estatus`, `vencimiento`),
  KEY `idx_origen` (`origen_tipo`, `origen_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `e1_cobros` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `cuenta_id` int(11) NOT NULL,
  `fecha` date NOT NULL,
  `monto` decimal(14,2) NOT NULL,
  `forma` varchar(20) NOT NULL DEFAULT 'transferencia' COMMENT 'transferencia | efectivo | tarjeta | cheque | otro',
  `referencia` varchar(100) DEFAULT NULL,
  `notas` varchar(255) DEFAULT NULL,
  `comprobante` varchar(255) DEFAULT NULL,
  `cancelado` tinyint(1) NOT NULL DEFAULT '0',
  `motivo_cancelacion` varchar(255) DEFAULT NULL,
  `creado_por` int(11) DEFAULT NULL,
  `creado_por_nombre` varchar(150) DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cuenta` (`cuenta_id`),
  KEY `idx_fecha` (`fecha`),
  CONSTRAINT `fk_e1_cobro_cuenta` FOREIGN KEY (`cuenta_id`) REFERENCES `e1_cuentas_cobrar` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `e1_gastos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fecha` date NOT NULL,
  `categoria` varchar(60) NOT NULL,
  `concepto` varchar(255) NOT NULL,
  `proveedor` varchar(150) DEFAULT NULL,
  `monto` decimal(14,2) NOT NULL COMMENT 'Total pagado, con IVA',
  `iva` decimal(14,2) NOT NULL DEFAULT '0.00',
  `forma` varchar(20) NOT NULL DEFAULT 'transferencia',
  `referencia` varchar(100) DEFAULT NULL,
  `incidencia_id` int(11) DEFAULT NULL COMMENT 'Orden de servicio a la que se carga (costo directo)',
  `incidencia_folio` varchar(100) DEFAULT NULL,
  `comprobante` varchar(255) DEFAULT NULL,
  `cancelado` tinyint(1) NOT NULL DEFAULT '0',
  `motivo_cancelacion` varchar(255) DEFAULT NULL,
  `creado_por` int(11) DEFAULT NULL,
  `creado_por_nombre` varchar(150) DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `actualizado_en` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_fecha` (`fecha`),
  KEY `idx_categoria` (`categoria`),
  KEY `idx_incidencia` (`incidencia_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Permisos por rol de la empresa (editables en Ajustes -> Privilegios)
INSERT IGNORE INTO `e1_permisos_rol` (`rol`, `modulo`, `permitido`) VALUES
('Administrador', 'incidencias', 1), ('Administrador', 'reportes', 1), ('Administrador', 'clientes', 1),
('Administrador', 'ventas', 1),      ('Administrador', 'inventario', 1), ('Administrador', 'usuarios', 1),
('Administrador', 'soporte', 1),     ('Administrador', 'informes', 1), ('Administrador', 'historial', 1),
('Administrador', 'cotizaciones', 1), ('Administrador', 'finanzas', 1),

('Supervisor', 'incidencias', 1), ('Supervisor', 'reportes', 1), ('Supervisor', 'clientes', 1),
('Supervisor', 'ventas', 1),      ('Supervisor', 'inventario', 1), ('Supervisor', 'usuarios', 0),
('Supervisor', 'soporte', 1),     ('Supervisor', 'informes', 1), ('Supervisor', 'historial', 0),
('Supervisor', 'cotizaciones', 1), ('Supervisor', 'finanzas', 0),

('Administrativo', 'incidencias', 1), ('Administrativo', 'reportes', 1), ('Administrativo', 'clientes', 1),
('Administrativo', 'ventas', 1),      ('Administrativo', 'inventario', 1), ('Administrativo', 'usuarios', 0),
('Administrativo', 'soporte', 0),     ('Administrativo', 'informes', 1), ('Administrativo', 'historial', 0),
('Administrativo', 'cotizaciones', 1), ('Administrativo', 'finanzas', 1),

('Técnico', 'incidencias', 1), ('Técnico', 'reportes', 1), ('Técnico', 'clientes', 0),
('Técnico', 'ventas', 0),      ('Técnico', 'inventario', 0), ('Técnico', 'usuarios', 0),
('Técnico', 'soporte', 1),     ('Técnico', 'informes', 0), ('Técnico', 'historial', 0),
('Técnico', 'cotizaciones', 0), ('Técnico', 'finanzas', 0);

SET FOREIGN_KEY_CHECKS = 1;
