-- Reglas simples de automatización: al dar de alta una incidencia, si
-- categoria/sucursal/prioridad coincide con valor_condicion, se sobreescribe
-- tecnico o prioridad con valor_accion. Sin regex ni condiciones compuestas
-- a propósito (ver backend/lib/automatizacion_helpers.php).
CREATE TABLE IF NOT EXISTS reglas_automatizacion (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(150) NOT NULL,
    campo_condicion ENUM('categoria', 'sucursal', 'prioridad') NOT NULL,
    valor_condicion VARCHAR(100) NOT NULL,
    accion ENUM('asignar_tecnico', 'cambiar_prioridad') NOT NULL,
    valor_accion VARCHAR(100) NOT NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    orden INT NOT NULL DEFAULT 0,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
