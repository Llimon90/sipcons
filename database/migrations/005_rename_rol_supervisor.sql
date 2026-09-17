-- Migración 005: renombra el rol "Técnico/Administrativo" a "Supervisor"
--
-- Solo cambia la etiqueta del rol, no sus privilegios: los módulos
-- habilitados en permisos_rol se conservan tal cual quedaron configurados.
--
-- Debe aplicarse junto con el despliegue del código que ya usa "Supervisor"
-- como valor del rol (auth/permisos.php, usuarios.html, script-user.js):
-- si se aplica antes, los usuarios con ese rol perderán temporalmente sus
-- permisos (el código buscará "Supervisor" y no lo encontrará en la BD);
-- si se aplica después, seguirá funcionando con el nombre viejo hasta que
-- se ejecute esta migración.
--
-- Cómo aplicar: igual que las migraciones anteriores, primero en apptest.

UPDATE `usuarios` SET `rol` = 'Supervisor' WHERE `rol` = 'Técnico/Administrativo';

UPDATE `permisos_rol` SET `rol` = 'Supervisor' WHERE `rol` = 'Técnico/Administrativo';
