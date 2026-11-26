-- =====================================================================
--  Migración 004: contratación por transferencia
--
--  El administrador de una empresa elige plan y ciclo desde el sistema,
--  recibe una referencia y los datos bancarios, y puede subir su
--  comprobante. La plataforma confirma el pago (o lo rechaza) desde el
--  panel: nada se activa sin esa confirmación.
--
--  solicitudes_pago            una fila por solicitud de contratación o renovación
--  empresas.verificacion_hasta acceso provisional mientras se verifica el pago
--
--  Los datos bancarios se guardan en plataforma_config (Configuración del
--  panel), no requieren columnas nuevas.
--
--  Requiere la migración 002. Se puede importar más de una vez sin error.
-- =====================================================================

SET NAMES utf8mb4;

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

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'empresas' AND COLUMN_NAME = 'verificacion_hasta') = 0,
  'ALTER TABLE `empresas` ADD COLUMN `verificacion_hasta` date DEFAULT NULL COMMENT ''Acceso provisional mientras la plataforma verifica un pago'' AFTER `pagado_hasta`', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
