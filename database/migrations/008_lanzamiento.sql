-- 008: Ajustes para el lanzamiento comercial.
--
-- Central:
--   login_intentos: intentos fallidos de inicio de sesión (bloqueo temporal
--     tras varios fallos por correo o por IP).
--   pagos.tipo: pago (extiende la vigencia) | abono (pago parcial, no la
--     extiende) | nota_credito (descuento o devolución; se guarda en negativo).
--   planes.permite_marca = 1 en todos: logo y colores incluidos en cualquier
--     plan (los planes se distinguen solo por el número de usuarios).
--
-- En cada empresa (tablas e<ID>_):
--   inventario_articulos.imagen: foto del producto (aparece en el PDF de cotización).
--
-- Se puede ejecutar más de una vez. Para deshacerlo: 008_lanzamiento_revertir.sql.

CREATE TABLE IF NOT EXISTS `login_intentos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `usuario` varchar(190) NOT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_usuario` (`usuario`, `creado_en`),
  KEY `idx_ip` (`ip`, `creado_en`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pagos' AND COLUMN_NAME = 'tipo') = 0,
    'ALTER TABLE pagos ADD COLUMN tipo VARCHAR(20) NOT NULL DEFAULT ''pago'' COMMENT ''pago | abono | nota_credito'' AFTER empresa_id',
    'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'planes' AND COLUMN_NAME = 'permite_marca') > 0,
    'UPDATE planes SET permite_marca = 1',
    'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

DROP PROCEDURE IF EXISTS servidesk_008;

DELIMITER $$
CREATE PROCEDURE servidesk_008()
BEGIN
    DECLARE fin INT DEFAULT 0;
    DECLARE eid INT;
    DECLARE c CURSOR FOR SELECT id FROM empresas;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET fin = 1;
    OPEN c;
    ciclo: LOOP
        FETCH c INTO eid;
        IF fin THEN LEAVE ciclo; END IF;
        IF (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = CONCAT('e', eid, '_inventario_articulos')) > 0
           AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = CONCAT('e', eid, '_inventario_articulos') AND COLUMN_NAME = 'imagen') = 0 THEN
            SET @s = CONCAT('ALTER TABLE `e', eid, '_inventario_articulos` ADD COLUMN imagen VARCHAR(255) DEFAULT NULL ',
                'COMMENT ''Foto del producto (uploads/e<ID>/inventario/...)''');
            PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
        END IF;
    END LOOP;
    CLOSE c;
END$$
DELIMITER ;

CALL servidesk_008();
DROP PROCEDURE servidesk_008;
