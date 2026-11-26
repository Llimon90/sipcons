-- Migración 033: cuentas por cobrar (CxC)
--
-- Objetivo: dar seguimiento a lo que cada cliente debe. Es un módulo
-- independiente del registro de ventas de equipos (`ventas`/`venta_detalles`,
-- que nunca guardó un importe en dinero, solo datos de garantía/calibración):
-- una CxC se crea a mano desde el módulo de Cuentas por Cobrar o al convertir
-- una cotización en venta (backend/convertir-cotizacion-venta.php), con un
-- `concepto` libre (folio de venta, de cotización, etc.) en vez de una FK
-- obligatoria a `ventas`. `venta_id` queda como referencia OPCIONAL para las
-- que sí nacen de una conversión de cotización.
--
-- Cada abono en `pagos_cxc` descuenta `saldo_pendiente` dentro de una
-- transacción. `estatus` se recalcula al vuelo al listar
-- (backend/obtener-cuentas-por-cobrar.php marca 'Vencida' lo que tenga saldo > 0
-- y fecha_vencimiento ya pasada), igual que el patrón ya usado en
-- backend/generador_tickets.php para no depender de un cron.
--
-- 100% aditiva: solo crea tablas nuevas.
--
-- Cómo aplicar: igual que las migraciones anteriores, primero en apptest.

CREATE TABLE IF NOT EXISTS `cuentas_por_cobrar` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `cliente_id` INT NOT NULL,
  `cliente` VARCHAR(150) NOT NULL,
  `sucursal` VARCHAR(150) NULL,
  `concepto` VARCHAR(255) NOT NULL,
  `venta_id` INT NULL,
  `cotizacion_id` INT NULL,
  `monto_total` DECIMAL(10,2) NOT NULL,
  `saldo_pendiente` DECIMAL(10,2) NOT NULL,
  `fecha_emision` DATE NOT NULL,
  `fecha_vencimiento` DATE NOT NULL,
  `estatus` ENUM('Pendiente','Parcial','Pagada','Vencida') NOT NULL DEFAULT 'Pendiente',
  `creado_por` VARCHAR(150) NULL,
  `creado_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cxc_cliente_id` (`cliente_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `pagos_cxc` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `cxc_id` INT UNSIGNED NOT NULL,
  `monto` DECIMAL(10,2) NOT NULL,
  `fecha` DATE NOT NULL,
  `metodo_pago` VARCHAR(50) NULL,
  `referencia` VARCHAR(100) NULL,
  `notas` VARCHAR(255) NULL,
  `usuario_id` INT NULL,
  `usuario_nombre` VARCHAR(150) NULL,
  `creado_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pago_cxc_id` (`cxc_id`),
  CONSTRAINT `fk_pago_cxc` FOREIGN KEY (`cxc_id`) REFERENCES `cuentas_por_cobrar` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
