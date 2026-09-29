-- Migración 013: nombres claros para dos columnas de incidencias
--
--   numero -> reporte_cliente  (el # de reporte/incidencia que da el cliente, ej. ST-66477)
--   equipo -> categoria        (Mr. Tienda/Mr. Chef, Calimax, Recolección, Otros...)
--
-- Solo cambia el nombre: tipo, longitud, NULL, valor por defecto, collation,
-- comentario, índices y datos se conservan. OJO: NO es compatible hacia
-- atrás. El código desplegado junto con esta migración ya usa los nombres
-- nuevos, así que migración y código deben aplicarse juntos (primero en
-- apptest). Las columnas `equipo` de padron_equipos y venta_detalles NO se
-- tocan.
--
-- El hosting usa MySQL 5.7 / MariaDB < 10.5, que no soporta RENAME COLUMN.
-- Con CHANGE COLUMN hay que repetir la definición completa de la columna;
-- para no escribirla a mano (y arriesgar cambiar el tipo o perder datos),
-- el paso 1 la lee de information_schema y arma el ALTER exacto.
--
-- Cómo aplicar: primero en apptest, en la pestaña SQL de phpMyAdmin con la
-- base de datos seleccionada. Debe ejecutarse una sola vez.

-- ---------------------------------------------------------------------------
-- PASO 1 (solo lectura): genera el ALTER. Revisa el resultado: deben
-- aparecer las DOS columnas. Si sale NULL, ya se aplicó o la BD no es la
-- correcta.
-- ---------------------------------------------------------------------------
SELECT CONCAT(
    'ALTER TABLE `incidencias` ',
    GROUP_CONCAT(
        CONCAT(
            'CHANGE COLUMN `', COLUMN_NAME, '` `',
            IF(COLUMN_NAME = 'numero', 'reporte_cliente', 'categoria'), '` ',
            COLUMN_TYPE,
            IF(CHARACTER_SET_NAME IS NULL, '',
               CONCAT(' CHARACTER SET ', CHARACTER_SET_NAME, ' COLLATE ', COLLATION_NAME)),
            IF(IS_NULLABLE = 'NO', ' NOT NULL', ' NULL'),
            -- MySQL guarda el default sin comillas; MariaDB 10.2+ ya entre comillas o 'NULL'
            CASE
                WHEN COLUMN_DEFAULT IS NULL THEN IF(IS_NULLABLE = 'YES', ' DEFAULT NULL', '')
                WHEN COLUMN_DEFAULT = 'NULL' THEN ' DEFAULT NULL'
                WHEN LEFT(COLUMN_DEFAULT, 1) = '''' THEN CONCAT(' DEFAULT ', COLUMN_DEFAULT)
                ELSE CONCAT(' DEFAULT ', QUOTE(COLUMN_DEFAULT))
            END,
            IF(COLUMN_COMMENT = '', '', CONCAT(' COMMENT ', QUOTE(COLUMN_COMMENT)))
        )
        ORDER BY ORDINAL_POSITION
        SEPARATOR ', '
    ),
    ';'
) AS sentencia
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'incidencias'
  AND COLUMN_NAME IN ('numero', 'equipo')
HAVING COUNT(*) = 2;

-- ---------------------------------------------------------------------------
-- PASO 2: copia el texto completo de la columna "sentencia" y ejecútalo.
-- (En phpMyAdmin, si se ve cortado, usa "+ Opciones" -> "Textos completos".)
-- Se verá parecido a:
--
-- ALTER TABLE `incidencias`
--     CHANGE COLUMN `numero` `reporte_cliente` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
--     CHANGE COLUMN `equipo` `categoria`       varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL;
-- ---------------------------------------------------------------------------

-- PASO 3 (verificación): deben salir reporte_cliente y categoria.
-- SHOW COLUMNS FROM `incidencias` WHERE Field IN ('reporte_cliente', 'categoria', 'numero', 'equipo');

-- Reversa (si hay que volver al código anterior): igual que el paso 1,
-- cambiando los nombres: COLUMN_NAME IN ('reporte_cliente', 'categoria') y
-- IF(COLUMN_NAME = 'reporte_cliente', 'numero', 'equipo').
