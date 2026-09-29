-- Migración 014: alias_tecnico para los técnicos con nombre completo
--
-- Contexto: desde que los selects de técnico se cargan de la tabla usuarios
-- (obtener_tecnicos.php), lo que se guarda en incidencias.tecnico es
-- alias_tecnico o, si no hay, usuarios.nombre. Varios usuarios tienen el
-- nombre completo ("José Armando López Lira") y las incidencias históricas
-- el nombre corto ("Jose López"), así que filtros, métricas y dashboard no
-- les encontraban nada. Detectado con backend/diagnostico_tecnicos.php.
--
-- El alias es exactamente el texto que ya existe en las incidencias
-- históricas: así lo nuevo se guarda igual que lo viejo y todo coincide,
-- sin reescribir el histórico.
--
-- Si un usuario no existe en la BD (pruebas vs. producción) el UPDATE
-- simplemente no afecta filas. Se puede ejecutar más de una vez.

-- 1. Alias con el nombre histórico -----------------------------------------
UPDATE `usuarios` SET `alias_tecnico` = 'Jose López'          WHERE `usuario` = 'Jose.l';
UPDATE `usuarios` SET `alias_tecnico` = 'Victor Hugo Cordoba' WHERE `usuario` = 'victor.c';
UPDATE `usuarios` SET `alias_tecnico` = 'Humberto Vázquez'    WHERE `usuario` = 'humberto.v';
UPDATE `usuarios` SET `alias_tecnico` = 'Tomás Valdéz'        WHERE `usuario` = 'tomas.v';
UPDATE `usuarios` SET `alias_tecnico` = 'Mauricio Díaz'       WHERE `usuario` = 'mauricio.d';
UPDATE `usuarios` SET `alias_tecnico` = 'Jacob Ventura'       WHERE `usuario` = 'Jacob.v';
UPDATE `usuarios` SET `alias_tecnico` = 'Luis Limón'          WHERE `usuario` = 'Luis.l';
-- Homónimos, personas distintas: ernesto.c es el "Ernesto Chávez" del
-- histórico (su nombre tenía un espacio al final). echavez ("Ernesto Chávez
-- Rice") NO lleva alias: sus incidencias se guardan con su nombre completo y
-- los filtros comparan técnico completo, así que no se mezclan.
UPDATE `usuarios` SET `alias_tecnico` = 'Ernesto Chávez'      WHERE `usuario` = 'ernesto.c';
UPDATE `usuarios` SET `alias_tecnico` = NULL                  WHERE `usuario` = 'echavez';

-- 2. Incidencias guardadas con el nombre nuevo desde el cambio de selects --
-- (REPLACE distingue acentos y mayúsculas: solo cambia el texto exacto)
UPDATE `incidencias` SET `tecnico` = REPLACE(`tecnico`, 'Luis Alberto Limón', 'Luis Limón')
 WHERE `tecnico` LIKE BINARY '%Luis Alberto Limón%';
UPDATE `incidencias` SET `tecnico` = REPLACE(`tecnico`, 'Mauricio Diaz', 'Mauricio Díaz')
 WHERE `tecnico` LIKE BINARY '%Mauricio Diaz%';

-- 3. Correcciones del histórico ---------------------------------------------
-- Error de dedo: el usuario se llama Saavedra; así coincide sin alias.
UPDATE `incidencias` SET `tecnico` = REPLACE(`tecnico`, 'Manuel Eduardo Saveedra', 'Manuel Eduardo Saavedra')
 WHERE `tecnico` LIKE BINARY '%Manuel Eduardo Saveedra%';
UPDATE `incidencias` SET `tecnico` = 'Victor Hugo Cordoba'
 WHERE `tecnico` = BINARY 'Victor Cordoba';
-- Registro histórico: corresponde al Ernesto Chávez de ernesto.c.
UPDATE `incidencias` SET `tecnico` = 'Jose López/Ernesto Chávez'
 WHERE `tecnico` = BINARY 'Jose López Y Ernesto Chavez';

-- Técnicos que ya no laboran (p. ej. Hoscar Martínez) no se tocan: sus
-- incidencias conservan el nombre y siguen contando en estadísticas.

-- Verificación: cada técnico debe mostrar sus incidencias (> 0).
-- SELECT u.usuario, COALESCE(NULLIF(TRIM(u.alias_tecnico), ''), TRIM(u.nombre)) AS nombre_usado,
--        (SELECT COUNT(*) FROM incidencias i
--          WHERE CONCAT('/', REPLACE(REPLACE(i.tecnico, ' /', '/'), '/ ', '/'), '/')
--                LIKE CONCAT('%/', COALESCE(NULLIF(TRIM(u.alias_tecnico), ''), TRIM(u.nombre)), '/%')) AS incidencias
--   FROM usuarios u
--  WHERE u.rol IN ('Técnico', 'Técnico/Administrador', 'Supervisor', 'Administrador', 'Programador')
--  ORDER BY incidencias;
