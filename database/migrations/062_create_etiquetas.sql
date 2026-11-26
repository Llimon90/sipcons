-- Etiquetas libres para categorizar incidencias más allá de categoría/prioridad.
-- Sin FK hacia `incidencias` (el resto del esquema tampoco la usa para esa
-- tabla; se valida la existencia desde el backend en vez de a nivel de BD).
CREATE TABLE IF NOT EXISTS etiquetas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(60) NOT NULL,
    color VARCHAR(7) NOT NULL DEFAULT '#888888',
    UNIQUE KEY uq_etiquetas_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS incidencia_etiquetas (
    incidencia_id INT NOT NULL,
    etiqueta_id INT NOT NULL,
    PRIMARY KEY (incidencia_id, etiqueta_id),
    KEY idx_incidencia_etiquetas_etiqueta (etiqueta_id),
    CONSTRAINT fk_incidencia_etiquetas_etiqueta FOREIGN KEY (etiqueta_id) REFERENCES etiquetas (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
