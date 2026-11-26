-- 018: finanzas del cliente (D-48) y serie de refacciones (D-45). Versión 2.18.0.
--
-- En cada empresa (tablas e<ID>_):
--   clientes.limite_credito          límite de crédito opcional (NULL = sin límite); avisa, no bloquea.
--   cobros.tipo                      'cobro' | 'nota_credito'. Una nota de crédito reduce el saldo de
--                                    la cuenta igual que un cobro, pero no es dinero recibido (no
--                                    cuenta en el flujo). Se cancela, no se borra (D-22).
--   inventario_articulos.controla_serie   1 = al aplicarlo en una orden se pide el N/S de cada pieza.
--   inventario_movimientos.numero_serie   series de las piezas de ese movimiento (separadas por coma).
-- Se puede ejecutar más de una vez.

DROP PROCEDURE IF EXISTS servidesk_018_columna;
DROP PROCEDURE IF EXISTS servidesk_018_empresa;
DROP PROCEDURE IF EXISTS servidesk_018;

DELIMITER $$
CREATE PROCEDURE servidesk_018_columna(IN p VARCHAR(20), IN tabla VARCHAR(64), IN columna VARCHAR(64), IN definicion TEXT)
BEGIN
    IF (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = CONCAT(p, tabla) AND COLUMN_NAME = columna) = 0 THEN
        SET @s = CONCAT('ALTER TABLE `', p, tabla, '` ADD COLUMN ', columna, ' ', definicion);
        PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
    END IF;
END$$

CREATE PROCEDURE servidesk_018_empresa(IN p VARCHAR(20))
BEGIN
    CALL servidesk_018_columna(p, 'clientes', 'limite_credito', 'DECIMAL(14,2) DEFAULT NULL COMMENT ''Límite de crédito (NULL = sin límite); D-48''');
    CALL servidesk_018_columna(p, 'cobros', 'tipo', 'VARCHAR(15) NOT NULL DEFAULT ''cobro'' COMMENT ''cobro | nota_credito (D-48)''');
    CALL servidesk_018_columna(p, 'inventario_articulos', 'controla_serie', 'TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''1 = pide el N/S de cada pieza al aplicarlo (D-45)''');
    CALL servidesk_018_columna(p, 'inventario_movimientos', 'numero_serie', 'VARCHAR(255) DEFAULT NULL COMMENT ''Series de las piezas del movimiento (D-45)''');
END$$

CREATE PROCEDURE servidesk_018()
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
            AND TABLE_NAME = CONCAT('e', eid, '_cobros')) > 0 THEN
            CALL servidesk_018_empresa(CONCAT('e', eid, '_'));
        END IF;
    END LOOP;
    CLOSE c;
END$$
DELIMITER ;

CALL servidesk_018();
DROP PROCEDURE servidesk_018;
DROP PROCEDURE servidesk_018_empresa;
DROP PROCEDURE servidesk_018_columna;
