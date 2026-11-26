-- Plantillas de respuesta rápida para que el técnico no reescriba lo mismo
-- siempre (se insertan en el campo de notas del detalle de la incidencia).
CREATE TABLE IF NOT EXISTS plantillas_respuesta (
    id INT AUTO_INCREMENT PRIMARY KEY,
    titulo VARCHAR(150) NOT NULL,
    contenido TEXT NOT NULL,
    categoria VARCHAR(100) NULL,
    creado_por VARCHAR(150) NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
