-- Migración 007: contactos de cliente con nombre, teléfono y email propios
--
-- Objetivo: que cada cliente pueda tener varios contactos y de cada uno se
-- registre por separado nombre, teléfono y email (antes solo existía el texto
-- "Juan; Pedro" en clientes.contactos).
--
-- 100% aditiva: solo crea una tabla nueva. La columna clientes.contactos NO se
-- toca: la aplicación la mantiene sincronizada con los nombres (separados por
-- "; ") para que el listado, la búsqueda, los reportes y el desplegable de
-- "Reporta" en incidencias sigan funcionando igual.
--
-- No hace falta migrar datos: al abrir un cliente que aún no tiene filas aquí,
-- la pantalla muestra los nombres del texto viejo y al guardar se crean las
-- filas. Mientras esta tabla no exista, el sistema sigue funcionando con el
-- texto (solo que sin teléfono/email).
--
-- Cómo aplicar: igual que las migraciones anteriores, primero en apptest.

CREATE TABLE IF NOT EXISTS `cliente_contactos` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `cliente_id` INT NOT NULL,
  `nombre` VARCHAR(150) NOT NULL,
  `telefono` VARCHAR(50) NULL,
  `email` VARCHAR(150) NULL,
  PRIMARY KEY (`id`),
  KEY `idx_cliente_id` (`cliente_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
