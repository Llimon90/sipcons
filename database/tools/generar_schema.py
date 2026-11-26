"""
Genera database/schema.sql para una instalación nueva de Servidesk multiempresa:
  1. tablas centrales (empresas, usuarios, pagos, analytics_eventos)
  2. la empresa 1 ("demo")
  3. las tablas de la empresa 1 (database/plantilla_empresa.sql con prefijo e1_)

Uso:  python database/tools/generar_schema.py
"""
import json
from pathlib import Path

DB = Path(__file__).resolve().parent.parent

MARCA_DEMO = {
    "nombre": "Servidesk",
    "eslogan": "Gestión de servicios y mantenimiento",
    "logo": "img/logo.svg",
    "colores": {"primario": "#3498db", "secundario": "#1a4de4", "sidebar": "#2c3e50"},
    "categorias": ["Mantenimiento correctivo", "Mantenimiento preventivo", "Instalación",
                   "Garantía", "Diagnóstico", "Otros"],
    "prefijo_folio": "SD",
    "zona_horaria": "America/Mexico_City",
}

CENTRAL = """-- ---------------------------------------------------------------------
-- Tablas centrales de la plataforma (sin prefijo)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `empresas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `slug` varchar(60) NOT NULL COMMENT 'Identificador corto y único (acme)',
  `nombre` varchar(150) NOT NULL,
  `estado` varchar(20) NOT NULL DEFAULT 'prueba' COMMENT 'demo | prueba | activa | suspendida | cancelada',
  `prueba_hasta` date DEFAULT NULL,
  `pagado_hasta` date DEFAULT NULL COMMENT 'Último día cubierto por los pagos (estado activa)',
  `verificacion_hasta` date DEFAULT NULL COMMENT 'Acceso provisional mientras la plataforma verifica un pago',
  `marca` longtext COMMENT 'JSON: nombre, eslogan, logo, colores, categorias, prefijo_folio, zona_horaria',
  `contacto_nombre` varchar(150) DEFAULT NULL,
  `contacto_correo` varchar(150) DEFAULT NULL,
  `contacto_telefono` varchar(50) DEFAULT NULL,
  `notas` text,
  `registro_ip` varchar(45) DEFAULT NULL COMMENT 'IP desde la que se registró (autoservicio)',
  `plan_id` int(11) DEFAULT NULL,
  `ciclo` varchar(10) NOT NULL DEFAULT 'mensual' COMMENT 'mensual | anual',
  `precio` decimal(12,2) DEFAULT NULL COMMENT 'Precio pactado por ciclo; NULL = precio del plan',
  `rfc` varchar(20) DEFAULT NULL,
  `razon_social` varchar(200) DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `actualizado_en` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_slug` (`slug`),
  KEY `idx_registro_ip` (`registro_ip`, `creado_en`),
  KEY `idx_plan` (`plan_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Catálogo de planes (precios y límites que se aplican a cada empresa)
CREATE TABLE IF NOT EXISTS `planes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `clave` varchar(30) NOT NULL,
  `nombre` varchar(80) NOT NULL,
  `descripcion` varchar(255) DEFAULT NULL,
  `precio_mensual` decimal(12,2) NOT NULL DEFAULT '0.00',
  `precio_anual` decimal(12,2) NOT NULL DEFAULT '0.00',
  `max_usuarios` int(11) DEFAULT NULL COMMENT 'NULL = sin límite',
  `permite_marca` tinyint(1) NOT NULL DEFAULT '1' COMMENT '1 = el cliente puede personalizar logo, colores y textos',
  `activo` tinyint(1) NOT NULL DEFAULT '1',
  `orden` int(11) NOT NULL DEFAULT '0',
  `creado_en` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_clave` (`clave`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Notas internas de seguimiento comercial de cada empresa (no las ve el cliente)
CREATE TABLE IF NOT EXISTS `empresa_notas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `empresa_id` int(11) NOT NULL,
  `nota` text NOT NULL,
  `autor` varchar(150) DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_empresa` (`empresa_id`, `creado_en`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ajustes de negocio editables desde el panel (sustituyen a los valores por defecto)
CREATE TABLE IF NOT EXISTS `plataforma_config` (
  `clave` varchar(60) NOT NULL,
  `valor` text,
  `actualizado_en` datetime DEFAULT NULL,
  PRIMARY KEY (`clave`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `usuarios` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `empresa_id` int(11) DEFAULT NULL COMMENT 'NULL = superadministrador de la plataforma',
  `nombre` varchar(100) NOT NULL,
  `alias_tecnico` varchar(150) DEFAULT NULL COMMENT 'Nombre exacto tal como aparece en incidencias.tecnico, si difiere de usuarios.nombre',
  `correo` varchar(100) NOT NULL,
  `telefono` varchar(20) NOT NULL,
  `usuario` varchar(100) NOT NULL COMMENT 'Único en toda la plataforma (normalmente el correo)',
  `password` varchar(255) NOT NULL,
  `rol` varchar(50) NOT NULL,
  `puede_cerrar` tinyint(1) NOT NULL DEFAULT '1' COMMENT '0 = no puede poner Cerrado con/sin factura (lo decide el administrador de la empresa)',
  `puede_autorizar_descuentos` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1 = puede rebasar el descuento máximo en cotizaciones',
  `fecha_creacion` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `tema` varchar(10) NOT NULL DEFAULT 'claro' COMMENT 'Tema de la interfaz: claro | oscuro',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_usuario` (`usuario`),
  KEY `idx_empresa` (`empresa_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `pagos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `empresa_id` int(11) NOT NULL,
  `tipo` varchar(20) NOT NULL DEFAULT 'pago' COMMENT 'pago | abono | nota_credito',
  `monto` decimal(12,2) NOT NULL DEFAULT '0.00',
  `fecha_pago` date NOT NULL,
  `cubre_hasta` date NOT NULL COMMENT 'Nuevo pagado_hasta de la empresa',
  `metodo` varchar(40) DEFAULT NULL,
  `referencia` varchar(100) DEFAULT NULL,
  `notas` varchar(255) DEFAULT NULL,
  `factura` varchar(60) DEFAULT NULL COMMENT 'Folio o UUID del CFDI emitido',
  `registrado_por` varchar(150) DEFAULT NULL,
  `anulado` tinyint(1) NOT NULL DEFAULT '0',
  `anulado_motivo` varchar(255) DEFAULT NULL,
  `anulado_en` datetime DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_empresa` (`empresa_id`, `fecha_pago`),
  KEY `idx_fecha` (`fecha_pago`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Intentos fallidos de inicio de sesión (bloqueo temporal, auth/login.php)
CREATE TABLE IF NOT EXISTS `login_intentos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `usuario` varchar(190) NOT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_usuario` (`usuario`, `creado_en`),
  KEY `idx_ip` (`ip`, `creado_en`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Solicitudes de contratación por transferencia (las confirma la plataforma)
CREATE TABLE IF NOT EXISTS `solicitudes_pago` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `empresa_id` int(11) NOT NULL,
  `plan_id` int(11) NOT NULL,
  `ciclo` varchar(10) NOT NULL DEFAULT 'mensual' COMMENT 'mensual | anual',
  `monto` decimal(12,2) NOT NULL,
  `moneda` varchar(3) NOT NULL DEFAULT 'MXN',
  `referencia` varchar(30) DEFAULT NULL COMMENT 'Concepto que el cliente pone en su transferencia',
  `estado` varchar(15) NOT NULL DEFAULT 'pendiente' COMMENT 'pendiente | confirmada | rechazada | cancelada',
  `requiere_factura` tinyint(1) NOT NULL DEFAULT '0',
  `rfc` varchar(13) DEFAULT NULL,
  `razon_social` varchar(200) DEFAULT NULL,
  `regimen_fiscal` varchar(3) DEFAULT NULL COMMENT 'Clave del SAT (601, 612, 626...)',
  `codigo_postal` varchar(5) DEFAULT NULL COMMENT 'Domicilio fiscal',
  `uso_cfdi` varchar(4) DEFAULT NULL,
  `correo_factura` varchar(150) DEFAULT NULL,
  `comprobante` varchar(255) DEFAULT NULL COMMENT 'Archivo en storage/comprobantes/e<ID>/',
  `comprobante_nombre` varchar(255) DEFAULT NULL COMMENT 'Nombre original del archivo',
  `acceso_hasta` date DEFAULT NULL COMMENT 'Acceso provisional que se otorgó al solicitar',
  `motivo` varchar(255) DEFAULT NULL COMMENT 'Motivo del rechazo',
  `pago_id` int(11) DEFAULT NULL COMMENT 'Pago registrado al confirmar',
  `usuario_id` int(11) DEFAULT NULL,
  `solicitado_por` varchar(150) DEFAULT NULL,
  `resuelto_por` varchar(150) DEFAULT NULL,
  `resuelto_en` datetime DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_empresa` (`empresa_id`, `creado_en`),
  KEY `idx_estado` (`estado`, `creado_en`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bitácora de acciones de plataforma (altas, pagos, extensiones, suspensiones...)
CREATE TABLE IF NOT EXISTS `plataforma_eventos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `empresa_id` int(11) DEFAULT NULL,
  `accion` varchar(40) NOT NULL,
  `detalle` text COMMENT 'JSON con los datos de la acción',
  `usuario_nombre` varchar(150) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_empresa` (`empresa_id`, `creado_en`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `analytics_eventos` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `empresa_id` int(11) DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `usuario_id` int(11) DEFAULT NULL,
  `usuario` varchar(100) DEFAULT NULL,
  `usuario_nombre` varchar(150) DEFAULT NULL,
  `rol` varchar(60) DEFAULT NULL,
  `sesion_id` varchar(40) NOT NULL,
  `visita_id` varchar(40) NOT NULL,
  `tipo` varchar(24) NOT NULL,
  `pagina` varchar(100) DEFAULT NULL,
  `modulo` varchar(40) DEFAULT NULL,
  `elemento` varchar(200) DEFAULT NULL,
  `detalle` varchar(300) DEFAULT NULL,
  `valor` int(11) DEFAULT NULL,
  `extra` int(11) DEFAULT NULL,
  `referrer_pagina` varchar(100) DEFAULT NULL,
  `dispositivo` varchar(12) DEFAULT NULL,
  `navegador` varchar(24) DEFAULT NULL,
  `sistema` varchar(24) DEFAULT NULL,
  `viewport` varchar(12) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_an_creado` (`creado_en`),
  KEY `idx_an_empresa` (`empresa_id`, `creado_en`),
  KEY `idx_an_usuario` (`usuario_id`,`creado_en`),
  KEY `idx_an_tipo` (`tipo`,`creado_en`),
  KEY `idx_an_sesion` (`sesion_id`),
  KEY `idx_an_visita` (`visita_id`),
  KEY `idx_an_pagina` (`pagina`,`tipo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
"""


# Planes iniciales (precios de referencia en MXN, editables desde el panel).
# Anual = 10 mensualidades (2 meses gratis).
PLANES_SQL = """-- Planes iniciales (editables en el panel de plataforma > Planes)
INSERT IGNORE INTO `planes` (`id`, `clave`, `nombre`, `descripcion`, `precio_mensual`, `precio_anual`, `max_usuarios`, `permite_marca`, `orden`) VALUES
(1, 'basico', 'Básico', 'Para talleres y equipos pequeños', 690.00, 6900.00, 5, 1, 1),
(2, 'profesional', 'Profesional', 'Para empresas de servicio en crecimiento', 1490.00, 14900.00, 15, 1, 2),
(3, 'empresa', 'Empresa', 'Sin límite de usuarios', 2990.00, 29900.00, NULL, 1, 3);
"""


def sql_texto(v):
    return "'" + v.replace("\\", "\\\\").replace("'", "''") + "'"


if __name__ == "__main__":
    plantilla = (DB / "plantilla_empresa.sql").read_text(encoding="utf-8")
    cuerpo = plantilla[plantilla.index("CREATE TABLE"):]
    empresa_demo = (
        PLANES_SQL
        + "\nINSERT IGNORE INTO `empresas` (`id`, `slug`, `nombre`, `estado`, `plan_id`, `marca`) VALUES\n"
        f"(1, 'demo', 'Servidesk Demo', 'demo', 3, {sql_texto(json.dumps(MARCA_DEMO, ensure_ascii=False))});\n"
    )
    salida = (
        "-- =====================================================================\n"
        "--  Servidesk - esquema base (multiempresa)\n"
        "--  GENERADO por database/tools/generar_schema.py (no editar a mano;\n"
        "--  las tablas de empresa se editan en database/plantilla_empresa.sql).\n"
        "--\n"
        "--  Instalación nueva (phpMyAdmin o consola):\n"
        "--    1) database/schema.sql  -> plataforma + empresa 1 (demo) vacía\n"
        "--    2) database/seed.sql    -> (opcional) datos de la empresa demo\n"
        "--  Las demás empresas se crean desde la aplicación (registro o panel).\n"
        "--  Compatible con MySQL 5.7+ / MariaDB 10.3+. Todo en utf8mb4.\n"
        "-- =====================================================================\n\n"
        "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n"
        + CENTRAL
        + "\n-- ---------------------------------------------------------------------\n"
          "-- Empresa 1: demo pública (estado 'demo', se reinicia cada noche)\n"
          "-- ---------------------------------------------------------------------\n"
        + empresa_demo + "\n"
        + cuerpo.replace("{{p}}", "e1_")
        + "\nSET FOREIGN_KEY_CHECKS = 1;\n"
    )
    (DB / "schema.sql").write_text(salida, encoding="utf-8", newline="\n")
    print("schema.sql generado")
