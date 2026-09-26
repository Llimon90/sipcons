-- Migración 012: tema de visualización (claro/oscuro) por usuario
--
-- Objetivo: que cada usuario elija en Ajustes entre modo claro y oscuro y que
-- el sistema lo recuerde en cualquier dispositivo (escritorio o móvil) donde
-- inicie sesión. Se guarda en el usuario, no en el navegador.
--
-- 100% aditiva: agrega una columna con valor por defecto 'claro', así que
-- nadie cambia de apariencia hasta que lo elija. Mientras la columna no
-- exista, el sistema sigue funcionando en modo claro (y el modo oscuro solo
-- se recuerda en el navegador donde se eligió).
--
-- Cómo aplicar: primero en apptest. Debe ejecutarse una sola vez.

ALTER TABLE `usuarios`
    ADD COLUMN `tema` VARCHAR(10) NOT NULL DEFAULT 'claro'
        COMMENT 'Tema de la interfaz: claro | oscuro';
