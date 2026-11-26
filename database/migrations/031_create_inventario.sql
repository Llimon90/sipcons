-- Migración 031: inventario de productos/refacciones
--
-- Objetivo: catálogo de piezas/productos que SIPCONS usa en servicio o vende,
-- con su stock. `inventario_movimientos` es el historial (entradas, salidas,
-- ajustes); `stock_actual` en `inventario_productos` es la foto vigente, que se
-- recalcula en cada movimiento dentro de una transacción (ver
-- backend/registrar-movimiento-inventario.php). Nunca se escribe a mano.
--
-- 100% aditiva: solo crea tablas nuevas.
--
-- Cómo aplicar: igual que las migraciones anteriores, primero en apptest.

CREATE TABLE IF NOT EXISTS `inventario_productos` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `sku` VARCHAR(50) NOT NULL,
  `nombre` VARCHAR(150) NOT NULL,
  `descripcion` VARCHAR(255) NULL,
  `unidad` VARCHAR(30) NOT NULL DEFAULT 'pza',
  `stock_actual` INT NOT NULL DEFAULT 0,
  `stock_minimo` INT NOT NULL DEFAULT 0,
  `costo` DECIMAL(10,2) NOT NULL DEFAULT 0,
  `precio_venta` DECIMAL(10,2) NOT NULL DEFAULT 0,
  `activo` TINYINT(1) NOT NULL DEFAULT 1,
  `creado_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_sku` (`sku`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `inventario_movimientos` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `producto_id` INT UNSIGNED NOT NULL,
  `tipo` ENUM('Entrada','Salida','Ajuste') NOT NULL,
  `cantidad` INT NOT NULL,
  `motivo` VARCHAR(255) NULL,
  `referencia_tipo` VARCHAR(30) NULL,
  `referencia_id` INT NULL,
  `usuario_id` INT NULL,
  `usuario_nombre` VARCHAR(150) NULL,
  `creado_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_producto_id` (`producto_id`),
  CONSTRAINT `fk_mov_producto` FOREIGN KEY (`producto_id`) REFERENCES `inventario_productos` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
