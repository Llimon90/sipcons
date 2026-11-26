-- Marca de escalamiento: una incidencia abierta/sin asignar que entra al
-- último 25% de su tiempo de respuesta SLA (ver backend/lib/sla_helpers.php)
-- se marca escalada=1 una sola vez (backend/alertas_incidentes.php).
ALTER TABLE incidencias ADD COLUMN escalado TINYINT(1) NOT NULL DEFAULT 0 AFTER prioridad;
