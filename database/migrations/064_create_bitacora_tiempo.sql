-- Bitácora de tiempo invertido por un técnico en una incidencia (worklog).
CREATE TABLE IF NOT EXISTS bitacora_tiempo (
    id INT AUTO_INCREMENT PRIMARY KEY,
    incidencia_id INT NOT NULL,
    usuario_id INT NULL,
    usuario_nombre VARCHAR(150) NULL,
    minutos INT NOT NULL,
    descripcion VARCHAR(255) NULL,
    fecha DATE NOT NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_bitacora_tiempo_incidencia (incidencia_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
