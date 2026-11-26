-- Migración 015: prioridad/severidad de incidencias
--
-- Base para un SLA configurable por categoría+prioridad (antes el SLA era
-- fijo en código: 4h respuesta / 168h cierre, sin distinguir qué tan urgente
-- era la falla). "Media" como default conserva el comportamiento de las
-- incidencias ya existentes hasta que alguien las reclasifique.
--
-- Cómo aplicar: igual que las migraciones anteriores, primero en apptest.

ALTER TABLE `incidencias`
  ADD COLUMN `prioridad` ENUM('Baja','Media','Alta','Crítica') NOT NULL DEFAULT 'Media' AFTER `categoria`;
