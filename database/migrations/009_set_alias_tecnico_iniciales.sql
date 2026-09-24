-- Migración 009: llena alias_tecnico para los usuarios cuyo nombre no
-- coincide tal cual con lo que aparece en incidencias.tecnico.
--
-- Detectado con backend/diagnostico_tecnicos.php: de los 6 usuarios con rol
-- técnico, 3 no encontraban ninguna incidencia propia por diferencias de
-- acento o por tener el nombre completo (con apellido materno) cuando en
-- incidencias solo se guardó nombre + apellido paterno. Un cuarto caso
-- (tomas / Tomasvr25) son dos cuentas duplicadas de la misma persona; se
-- confirmó con el usuario que "tomas" es la cuenta que realmente se usa, así
-- que solo esa recibe el alias (Tomasvr25 se deja sin alias a propósito).
--
-- Solo afecta a los usuarios listados aquí; no toca incidencias.
--
-- Cómo aplicar: igual que las migraciones anteriores, primero en apptest.

UPDATE `usuarios` SET `alias_tecnico` = 'Jose López'    WHERE `usuario` = 'Jarmandoll';
UPDATE `usuarios` SET `alias_tecnico` = 'Mauricio Díaz' WHERE `usuario` = 'MauricioD';
UPDATE `usuarios` SET `alias_tecnico` = 'Tomás Valdéz'  WHERE `usuario` = 'tomas';
