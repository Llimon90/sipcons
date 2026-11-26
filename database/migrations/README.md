# Migraciones

`database/schema.sql` es la línea base: crea una instancia nueva completa.

Los cambios de esquema posteriores van aquí como archivos numerados
(`001_descripcion.sql`, `002_...`), escritos para poder ejecutarse más de una
vez sin romper nada (`IF NOT EXISTS`, `INSERT IGNORE`), y se reflejan también
en `schema.sql` para que las instalaciones nuevas no tengan que aplicarlos.
